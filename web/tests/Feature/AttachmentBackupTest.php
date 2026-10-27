<?php

namespace Tests\Feature;

use App\Exceptions\BackupException;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IncidentLog;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\User;
use App\Services\AttachmentBackupService;
use App\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class AttachmentBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        $this->directory = storage_path('framework/testing/attachment-backup-'.Str::uuid());
        config(['backups.attachments_temp_directory' => $this->directory]);
        Storage::fake('local');
        Http::preventStrayRequests();
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

    private function attachment(string $path = 'evidence/screenshot.jpg', string $bytes = 'sample image', array $attributes = []): Attachment
    {
        $branch = Branch::create(['name' => 'Branch '.Str::uuid(), 'code' => Str::uuid()]);
        Storage::disk('local')->put($path, $bytes);

        return Attachment::create([
            'branch_id' => $branch->id, 'disk' => 'local', 'path' => $path,
            'name' => 'Original image.jpg', 'mime' => 'image/jpeg', 'size' => strlen($bytes), ...$attributes,
        ]);
    }

    private function archive(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));

        return $zip;
    }

    public function test_only_active_administrators_can_download_attachments(): void
    {
        $this->post('/backups/attachments/download')->assertRedirect('/login');
        foreach (['viewer', 'operator', 'technician', 'supervisor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post('/backups/attachments/download')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => false]))
            ->post('/backups/attachments/download')->assertRedirect('/login');
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], glob($this->directory.'/*') ?: []);
    }

    public function test_zip_contains_registered_camera_and_incident_files_from_all_branches_with_a_recovery_manifest(): void
    {
        $image = $this->attachment();
        $device = Device::create(['asset_id' => 'CAM-TEST', 'name' => 'Camera', 'type' => 'IP Camera', 'branch_id' => $image->branch_id]);
        $observation = Observation::create(['device_id' => $device->id, 'date' => now()->toDateString()]);
        $image->update(['observation_id' => $observation->id]);
        $incident = IncidentReport::create(['reference' => 'IR-TEST', 'device_id' => $device->id,
            'branch_id' => $image->branch_id, 'title' => 'Test incident', 'priority' => 'medium',
            'category' => 'Camera offline', 'due_at' => now(), 'signed_document_url' => 'https://example.com/signed.pdf']);
        $log = IncidentLog::create(['incident_report_id' => $incident->id, 'action' => 'log', 'notes' => 'Test']);
        $document = $this->attachment('evidence/incident.pdf', '%PDF-sample', ['incident_log_id' => $log->id, 'name' => '../../original.pdf', 'mime' => 'application/pdf']);
        Storage::disk('local')->put('.env', 'NOT-IN-BACKUP');
        Storage::disk('local')->put('sessions/private-session', 'NOT-IN-BACKUP');
        Storage::disk('local')->put('evidence/unregistered.jpg', 'NOT-IN-BACKUP');
        $backup = app(AttachmentBackupService::class)->create();
        $zip = $this->archive($backup['path']);
        $this->assertSame(4, $zip->numFiles);
        $this->assertSame('sample image', $zip->getFromName('attachments/local/evidence/screenshot.jpg'));
        $this->assertSame('%PDF-sample', $zip->getFromName('attachments/local/evidence/incident.pdf'));
        $this->assertFalse($zip->locateName('attachments/local/evidence/unregistered.jpg'));
        $this->assertFalse($zip->locateName('.env'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $manifest['attachment_count']);
        $this->assertSame(2, $manifest['file_count']);
        $this->assertSame($image->id, $manifest['attachments'][0]['id']);
        $this->assertSame($observation->id, $manifest['attachments'][0]['observation_id']);
        $this->assertSame($log->id, $manifest['attachments'][1]['incident_log_id']);
        foreach ($manifest['attachments'] as $entry) {
            $this->assertSame(hash('sha256', $zip->getFromName($entry['archive_path'])), $entry['sha256']);
            $this->assertSame(strlen($zip->getFromName($entry['archive_path'])), $entry['bytes']);
        }
        $this->assertStringNotContainsString(base_path(), $zip->getFromName('manifest.json'));
        $this->assertStringContainsString('SQL', $zip->getFromName('README.txt'));
        $zip->close();
        $this->assertSame(2, $backup['attachments']);
        $this->assertSame(2, $backup['files']);
        $this->assertSame(filesize($backup['path']), $backup['bytes']);
        $this->assertMatchesRegularExpression('/^RoseWatch-attachments-.*\.zip$/', $backup['name']);
        $this->assertCount(1, glob($this->directory.'/*'));
        Storage::disk('local')->assertExists([$image->path, $document->path]);
        $this->assertDatabaseCount('attachments', 2);
        Http::assertNothingSent();
    }

    public function test_empty_evidence_still_downloads_a_valid_zip_with_manifest_and_instructions(): void
    {
        $backup = app(AttachmentBackupService::class)->create();
        $zip = $this->archive($backup['path']);
        $this->assertSame(2, $zip->numFiles);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame([], $manifest['attachments']);
        $this->assertSame(0, $manifest['file_count']);
        $zip->close();
    }

    public function test_missing_or_changed_files_abort_the_entire_zip_and_release_the_lock(): void
    {
        $attachment = $this->attachment();
        foreach (['missing', 'changed'] as $failure) {
            if ($failure === 'missing') {
                Storage::disk('local')->delete($attachment->path);
            } else {
                Storage::disk('local')->put($attachment->path, 'different length');
            }
            try {
                app(AttachmentBackupService::class)->create();
                $this->fail('Incomplete evidence must never produce a download.');
            } catch (BackupException $exception) {
                $this->assertStringContainsString('No ZIP was downloaded', $exception->getMessage());
            }
            $this->assertSame([], glob($this->directory.'/*'));
            $lock = Cache::lock('rosewatch:manual-attachment-backup', 10);
            $this->assertTrue($lock->get());
            $lock->release();
        }
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_other_disks_and_paths_outside_evidence_are_rejected_without_leaking_details(): void
    {
        $attachment = $this->attachment();
        foreach ([['local', 'evidence/../../.env'], ['local', '/etc/passwd'], ['local', 'sessions/private'], ['local', 'evidence\\file.jpg'], ['s3', 'evidence/file.jpg']] as [$disk, $path]) {
            $attachment->update(compact('disk', 'path'));
            try {
                app(AttachmentBackupService::class)->create();
                $this->fail('Unsupported evidence paths must not be exported.');
            } catch (BackupException $exception) {
                $this->assertStringContainsString('unsupported storage location', $exception->getMessage());
                $this->assertStringNotContainsString($path, $exception->getMessage());
            }
            $this->assertSame([], glob($this->directory.'/*'));
        }
        Http::assertNothingSent();
    }

    public function test_download_is_private_audited_and_removed_after_send(): void
    {
        $this->attachment();
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->postJson('/backups/attachments/download')->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $audit = AuditLog::where('action', 'Attachment backup prepared')->sole();
        $this->assertSame(['filename', 'bytes', 'attachments', 'files'], array_keys($audit->changes));
        $this->assertSame(1, $audit->changes['files']);
        $this->assertSame($admin->id, $audit->user_id);
        $path = $response->baseResponse->getFile()->getPathname();
        ob_start();
        $response->baseResponse->sendContent();
        $contents = ob_get_clean();
        $this->assertStringStartsWith('PK', $contents);
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], glob($this->directory.'/*'));
    }

    public function test_failed_audit_removes_zip_and_returns_a_safe_error(): void
    {
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('PRIVATE absolute paths and details'));
        $this->app->instance(AuditService::class, $audit);
        Log::shouldReceive('warning')->once()->with('Manual attachment backup failed.', ['exception_class' => RuntimeException::class]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/backups/attachments/download')
            ->assertRedirect('/backups')->assertSessionHas('error', 'The attachment ZIP could not be prepared. No download was created. Please contact your administrator.');
        $this->assertSame([], glob($this->directory.'/*'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_ajax_failure_returns_a_visible_json_error_instead_of_redirecting_to_html(): void
    {
        $attachment = $this->attachment();
        Storage::disk('local')->delete($attachment->path);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/backups/attachments/download')->assertStatus(503)
            ->assertJsonPath('message', 'One or more attachment files are missing or unreadable. No ZIP was downloaded. Ask your administrator to check evidence storage.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Location'));
        $this->assertStringNotContainsString($attachment->path, $response->getContent());
        $this->assertSame([], glob($this->directory.'/*'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_ajax_internal_errors_return_a_safe_message_and_remove_the_zip(): void
    {
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('PRIVATE server details'));
        $this->app->instance(AuditService::class, $audit);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/backups/attachments/download')->assertStatus(503)
            ->assertJsonPath('message', 'The attachment ZIP could not be prepared. No download was created. Please contact your administrator.');
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertSame([], glob($this->directory.'/*'));
    }

    public function test_backup_overview_lists_both_export_types_and_safe_attachment_totals(): void
    {
        $this->attachment();
        $admin = User::factory()->create(['role' => 'admin']);
        AuditLog::create(['user_id' => $admin->id, 'subject_type' => 'User', 'subject_id' => $admin->id,
            'action' => 'Database backup prepared', 'reason' => 'SQL export',
            'changes' => ['filename' => 'Test.sql', 'bytes' => 120]]);
        $this->actingAs($admin)->post('/backups/attachments/download')->assertOk();
        $response = $this->get('/backups')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('backups.attachments_available', true)->where('backups.attachment_count', 1)
            ->where('backups.attachment_bytes', strlen('sample image'))
            ->where('backups.recent_exports.0.type', 'attachments')
            ->where('backups.recent_exports.1.type', 'database')->missing('backups.recent_exports.0.path'));
        $this->assertStringNotContainsString('sample image', $response->getContent());
        $this->assertStringNotContainsString($this->directory, $response->getContent());
    }

    public function test_unsupported_local_disk_disables_attachment_backup_without_creating_files(): void
    {
        config(['filesystems.disks.local.driver' => 's3']);
        $service = app(AttachmentBackupService::class);
        $this->assertFalse($service->available());
        try {
            $service->create();
            $this->fail('Unavailable storage must not create an archive.');
        } catch (BackupException $exception) {
            $this->assertStringContainsString('unavailable', $exception->getMessage());
        }
        $this->assertSame([], glob($this->directory.'/*') ?: []);
    }

    public function test_download_requires_post_is_rate_limited_and_rejects_overlapping_preparations(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/backups/attachments/download')->assertStatus(405);
        $lock = Cache::lock('rosewatch:manual-attachment-backup', 10);
        $this->assertTrue($lock->get());
        try {
            app(AttachmentBackupService::class)->create();
            $this->fail('A second export must be blocked.');
        } catch (BackupException $exception) {
            $this->assertStringContainsString('Another attachment backup', $exception->getMessage());
        } finally {
            $lock->release();
        }
        $this->post('/backups/attachments/download')->assertOk();
        $this->post('/backups/attachments/download')->assertOk();
        $this->post('/backups/attachments/download')->assertStatus(429);
        $this->assertSame(2, AuditLog::where('action', 'Attachment backup prepared')->count());
    }
}
