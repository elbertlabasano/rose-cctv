<?php

namespace Tests\Feature;

use App\Exceptions\BackupException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MysqlBackupService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ManualBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        $this->directory = storage_path('framework/testing/manual-backup-'.Str::uuid());
        config([
            'backups.temp_directory' => $this->directory,
            'backups.mysql_dump_binary' => PHP_BINARY,
            'backups.google_drive_folder_id' => 'test_RoseWatch_backup_folder_12345',
        ]);
        Process::fake()->preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function dumper(array $override = []): MysqlBackupService
    {
        // This isolated connection config is synthetic. Never switch the app's guarded SQLite connection.
        $config = [
            'driver' => 'mysql', 'database' => 'test_backup', 'host' => '127.0.0.1',
            'port' => 3306, 'username' => 'test-user', 'password' => "test-secret\"\\\nline", ...$override,
        ];
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getConfig')->withNoArgs()->andReturn($config);
        $connection->shouldReceive('getConfig')->with('driver')->andReturn($config['driver']);
        $manager = Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')->withNoArgs()->andReturn($connection);

        return new MysqlBackupService($manager);
    }

    private function fakeDump(): void
    {
        Process::fake(function (PendingProcess $process) {
            $output = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--result-file='));
            file_put_contents(substr($output, strlen('--result-file=')), '-- synthetic SQL backup');

            return Process::result();
        })->preventStrayProcesses();
    }

    public function test_only_active_administrators_can_view_or_download_backups(): void
    {
        $this->get('/backups')->assertRedirect('/login');
        $this->post('/backups/download')->assertRedirect('/login');
        foreach (['viewer', 'operator', 'technician', 'supervisor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/backups')->assertForbidden();
            $this->post('/backups/download')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => false]));
        $this->get('/backups')->assertRedirect('/login');
        Process::assertDidntRun(fn () => true);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_backup_page_uses_canonical_folder_url_and_only_exposes_export_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        AuditLog::create(['user_id' => $admin->id, 'subject_type' => 'User', 'subject_id' => $admin->id,
            'action' => 'Database backup prepared', 'reason' => 'Manual export',
            'changes' => ['filename' => 'RoseWatch-test.sql', 'bytes' => 120, 'path' => '/private/backup.sql', 'secret' => 'NEVER-EXPOSE']]);
        $response = $this->actingAs($admin)->get('/backups')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Backups/Index')
                ->where('backups.available', false)
                ->where('backups.drive_folder_url', 'https://drive.google.com/drive/folders/test_RoseWatch_backup_folder_12345')
                ->where('backups.recent_exports.0.actor', $admin->name)
                ->where('backups.recent_exports.0.filename', 'RoseWatch-test.sql')
                ->missing('backups.recent_exports.0.path')->missing('backups.recent_exports.0.secret')
                ->where('csrf_token', fn ($value) => is_string($value) && strlen($value) > 20));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('NEVER-EXPOSE', $response->getContent());
        config(['backups.google_drive_folder_id' => 'javascript:alert(1)']);
        $this->get('/backups')->assertInertia(fn (Assert $page) => $page->where('backups.drive_folder_url', null));
    }

    public function test_dump_uses_a_private_option_file_without_shell_or_password_arguments_and_cleans_it(): void
    {
        $credentials = null;
        Process::fake(function (PendingProcess $process) use (&$credentials) {
            $command = $process->command;
            $this->assertIsArray($command);
            $this->assertStringNotContainsString('test-secret', implode(' ', $command));
            $this->assertContains('--single-transaction', $command);
            $this->assertContains('--skip-lock-tables', $command);
            $this->assertContains('--no-tablespaces', $command);
            $this->assertContains('--hex-blob', $command);
            foreach (['SystemRoot', 'WINDIR', 'PATH', 'PATHEXT', 'TEMP', 'TMP'] as $key) {
                if (is_string(getenv($key)) && getenv($key) !== '') {
                    $this->assertSame(getenv($key), $process->environment[$key] ?? null);
                }
            }
            $this->assertArrayNotHasKey('DB_PASSWORD', $process->environment);
            $this->assertArrayNotHasKey('APP_KEY', $process->environment);
            $this->assertArrayNotHasKey('KEYCLOAK_CLIENT_SECRET', $process->environment);
            $credentials = substr($command[1], strlen('--defaults-extra-file='));
            $this->assertFileExists($credentials);
            $this->assertStringContainsString('password="test-secret\\"\\\\\\nline"', file_get_contents($credentials));
            $resultFile = collect($command)->first(fn ($arg) => str_starts_with($arg, '--result-file='));
            file_put_contents(substr($resultFile, strlen('--result-file=')), '-- synthetic SQL backup');

            return Process::result();
        })->preventStrayProcesses();
        $backup = $this->dumper()->create();
        $this->assertFileDoesNotExist($credentials);
        $this->assertFileExists($backup['path']);
        $this->assertSame(strlen('-- synthetic SQL backup'), $backup['bytes']);
        $this->assertMatchesRegularExpression('/^RoseWatch-database-.*\.sql$/', $backup['name']);
        $this->assertSame($this->directory, dirname($backup['path']));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_failure_removes_partial_sql_and_credentials_and_releases_the_backup_lock(): void
    {
        Process::fake(function (PendingProcess $process) {
            $resultFile = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--result-file='));
            file_put_contents(substr($resultFile, strlen('--result-file=')), 'partial SQL');

            return Process::result(errorOutput: 'Got error: 2004: socket (10106) private native error with credentials', exitCode: 2);
        })->preventStrayProcesses();
        try {
            $this->dumper()->create();
            $this->fail('A failed export must never be returned.');
        } catch (BackupException $exception) {
            $this->assertStringNotContainsString('credentials', $exception->getMessage());
            $this->assertSame(['exit_code' => 2, 'mysql_error' => 2004, 'socket_error' => 10106], $exception->diagnostics);
        }
        $this->assertSame([], glob($this->directory.'/*'));
        $lock = Cache::lock('rosewatch:manual-database-backup', 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_missing_binary_unsupported_driver_and_concurrent_export_cannot_start_a_dump(): void
    {
        config(['backups.mysql_dump_binary' => $this->directory.'/missing-binary']);
        $this->assertFalse($this->dumper()->available());
        config(['backups.mysql_dump_binary' => PHP_BINARY]);
        $this->assertFalse($this->dumper(['driver' => 'sqlite'])->available());
        $lock = Cache::lock('rosewatch:manual-database-backup', 10);
        $this->assertTrue($lock->get());
        try {
            $this->dumper()->create();
            $this->fail('A second export must be blocked.');
        } catch (BackupException $exception) {
            $this->assertStringContainsString('Another database backup', $exception->getMessage());
        } finally {
            $lock->release();
        }
        Process::assertDidntRun(fn () => true);
    }

    public function test_download_is_a_private_attachment_with_safe_audit_and_deletes_after_send(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->fakeDump();
        $this->app->instance(MysqlBackupService::class, $this->dumper());
        $response = $this->actingAs($admin)->post('/backups/download')->assertOk();
        $this->assertSame('application/sql', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $audit = AuditLog::where('action', 'Database backup prepared')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(['filename', 'bytes'], array_keys($audit->changes));
        $path = $response->baseResponse->getFile()->getPathname();
        ob_start();
        $response->baseResponse->sendContent();
        $contents = ob_get_clean();
        $this->assertSame('-- synthetic SQL backup', $contents);
        $this->assertFileDoesNotExist($path);
    }

    public function test_internal_errors_never_expose_connection_details_and_clean_prepared_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->fakeDump();
        $this->app->instance(MysqlBackupService::class, $this->dumper());
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('private password and SQL details'));
        $this->app->instance(AuditService::class, $audit);
        Log::shouldReceive('warning')->once()->with('Manual database backup failed.', ['exception_class' => RuntimeException::class]);
        $this->actingAs($admin)->post('/backups/download')->assertRedirect('/backups')
            ->assertSessionHas('error', 'The SQL backup could not be prepared. Please contact your administrator.');
        $this->assertSame([], glob($this->directory.'/*'));
    }

    public function test_download_requires_post_and_repeated_exports_are_throttled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->fakeDump();
        $this->app->instance(MysqlBackupService::class, $this->dumper());
        $this->actingAs($admin)->get('/backups/download')->assertStatus(405);
        $this->post('/backups/download')->assertOk();
        $this->post('/backups/download')->assertOk();
        $this->post('/backups/download')->assertStatus(429);
        $this->assertSame(2, AuditLog::where('action', 'Database backup prepared')->count());
    }
}
