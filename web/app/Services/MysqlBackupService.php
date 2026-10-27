<?php

namespace App\Services;

use App\Exceptions\BackupException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class MysqlBackupService
{
    public function __construct(private DatabaseManager $database) {}

    public function available(): bool
    {
        return in_array($this->database->connection()->getConfig('driver'), ['mysql', 'mariadb'], true)
            && function_exists('proc_open') && $this->binary() !== null;
    }

    /** @return array{path: string, name: string, bytes: int} */
    public function create(): array
    {
        if (! $this->available()) {
            throw new BackupException('SQL export is not available on this server. Ask your administrator to check backup setup.');
        }
        $timeout = (int) config('backups.timeout', 120);
        $lock = Cache::lock('rosewatch:manual-database-backup', $timeout + 30);
        if (! $lock->get()) {
            throw new BackupException('Another database backup is being prepared. Please try again after it finishes.');
        }

        $sqlPath = null;
        $credentialsPath = null;
        try {
            $config = $this->database->connection()->getConfig();
            $database = $config['database'] ?? '';
            if (! is_string($database) || ! preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_$-]*\z/D', $database)) {
                throw new BackupException('The database is not configured for SQL export.');
            }

            $directory = config('backups.temp_directory');
            $id = (string) Str::uuid();
            $sqlPath = $directory.'/export-'.$id.'.sql';
            $credentialsPath = $directory.'/credentials-'.$id.'.cnf';

            // Both artifacts remain private and are removed on normal completion or failure.
            register_shutdown_function(static function () use ($sqlPath, $credentialsPath) {
                foreach ([$sqlPath, $credentialsPath] as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            });
            $this->writePrivateFile($sqlPath, '');
            $this->writePrivateFile($credentialsPath, $this->credentials($config));

            // Passwords never appear in the process command line or shell interpolation.
            $result = Process::path(base_path())->env($this->processEnvironment())->timeout($timeout)->run([
                $this->binary(),
                '--defaults-extra-file='.$credentialsPath,
                '--single-transaction', '--quick', '--hex-blob', '--skip-lock-tables',
                '--no-tablespaces', '--default-character-set=utf8mb4',
                '--result-file='.$sqlPath,
                $database,
            ]);
            clearstatcache(true, $sqlPath);
            if (! $result->successful() || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
                $diagnostics = ['exit_code' => $result->exitCode()];
                // Extract numeric error codes only; never expose raw errors, paths or connection values.
                if (preg_match('/(?:Got error:\s*|\bERROR\s+)(\d{3,5})\b/i', $result->errorOutput(), $match)) {
                    $diagnostics['mysql_error'] = (int) $match[1];
                }
                if (preg_match('/\((1\d{4})\)/', $result->errorOutput(), $match)) {
                    $diagnostics['socket_error'] = (int) $match[1];
                }
                throw new BackupException('The SQL backup could not be prepared. No download was created. Please try again or contact your administrator.', $diagnostics);
            }

            return [
                'path' => $sqlPath,
                'name' => 'RoseWatch-database-'.now('Asia/Manila')->format('Y-m-d_His').'-'.substr($id, 0, 8).'.sql',
                'bytes' => filesize($sqlPath),
            ];
        } catch (Throwable $exception) {
            if ($sqlPath && is_file($sqlPath)) {
                @unlink($sqlPath);
            }
            throw $exception;
        } finally {
            if ($credentialsPath && is_file($credentialsPath)) {
                @unlink($credentialsPath);
            }
            $lock->release();
        }
    }

    private function binary(): ?string
    {
        $binary = (string) config('backups.mysql_dump_binary', 'mysqldump');
        if (is_file($binary) && is_executable($binary)) {
            return realpath($binary);
        }

        return (new ExecutableFinder)->find($binary);
    }

    private function processEnvironment(): array
    {
        // Web SAPIs can omit OS variables from the environment inherited by Process.
        // Windows networking requires SystemRoot; forward only this OS allowlist.
        $environment = [];
        foreach (['SystemRoot', 'WINDIR', 'PATH', 'PATHEXT', 'TEMP', 'TMP'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private function writePrivateFile(string $path, string $contents): void
    {
        $previousMask = umask(0077);
        try {
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new BackupException('Private backup storage is unavailable.');
            }
            $stream = fopen($path, 'xb');
            if ($stream === false) {
                throw new BackupException('Private backup storage is unavailable.');
            }
            try {
                if ($contents !== '' && fwrite($stream, $contents) !== strlen($contents)) {
                    throw new BackupException('Private backup storage is unavailable.');
                }
            } finally {
                fclose($stream);
            }
            chmod($path, 0600);
        } finally {
            umask($previousMask);
        }
    }

    private function credentials(array $config): string
    {
        $options = [
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 3306,
            'user' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
        ];
        if (! empty($config['unix_socket'])) {
            $options['socket'] = $config['unix_socket'];
        }
        if (defined('PDO::MYSQL_ATTR_SSL_CA') && ! empty($config['options'][\PDO::MYSQL_ATTR_SSL_CA])) {
            $options['ssl-ca'] = $config['options'][\PDO::MYSQL_ATTR_SSL_CA];
        }
        $contents = "[client]\n";
        foreach ($options as $key => $value) {
            if (! is_scalar($value) || str_contains((string) $value, "\0")) {
                throw new BackupException('The database connection is not configured for SQL export.');
            }
            $escaped = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], (string) $value);
            $contents .= $key.'="'.$escaped.'"'."\n";
        }

        return $contents;
    }
}
