<?php

namespace App\Services;

use App\Exceptions\BackupException;
use App\Models\Attachment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class AttachmentBackupService
{
    public function available(): bool
    {
        return class_exists(ZipArchive::class) && config('filesystems.disks.local.driver') === 'local';
    }

    /** @return array{path: string, name: string, bytes: int, attachments: int, files: int} */
    public function create(): array
    {
        if (! $this->available()) {
            throw new BackupException('Attachment backup is unavailable. Ask your administrator to enable PHP ZIP and local evidence storage.');
        }
        $timeout = (int) config('backups.timeout', 120);
        $lock = Cache::lock('rosewatch:manual-attachment-backup', $timeout + 30);
        if (! $lock->get()) {
            throw new BackupException('Another attachment backup is being prepared. Please try again after it finishes.');
        }

        $path = null;
        $manifestPath = null;
        $manifest = null;
        $zip = null;
        $opened = false;
        try {
            $started = microtime(true);
            $id = (string) Str::uuid();
            $directory = config('backups.attachments_temp_directory');
            $path = $directory.'/attachments-'.$id.'.zip';
            $manifestPath = $directory.'/manifest-'.$id.'.json';
            register_shutdown_function(static function () use ($path, $manifestPath) {
                foreach ([$path, $manifestPath] as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            });
            $manifest = $this->privateFile($manifestPath);
            fclose($this->privateFile($path));
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new BackupException('Private attachment backup storage is unavailable.');
            }
            $opened = true;
            $this->write($manifest, '{"format":"RoseWatch attachments","version":1,"created_at":'.json_encode(now()->toIso8601String()).',"attachments":[');
            $count = 0;
            $files = 0;

            // Only registered evidence is included; never traverse the private storage root.
            // UUID evidence files are immutable. Deletions during ZIP creation fail the export.
            foreach (Attachment::orderBy('id')->lazyById(200) as $attachment) {
                if (microtime(true) - $started >= $timeout) {
                    throw new BackupException('The attachment backup took too long. Please try again or ask your administrator for help.');
                }
                $source = $this->source($attachment);
                $archivePath = 'attachments/local/'.$attachment->path;
                $size = filesize($source);
                $hash = hash_file('sha256', $source);
                if ($size === false || $size !== (int) $attachment->size || $hash === false) {
                    throw new BackupException('An attachment could not be verified. No ZIP was downloaded. Ask your administrator to check evidence storage.');
                }
                if ($zip->locateName($archivePath) === false) {
                    if (! $zip->addFile($source, $archivePath)) {
                        throw new BackupException('An attachment could not be added. No ZIP was downloaded.');
                    }
                    $files++;
                }
                $metadata = [
                    'id' => $attachment->id, 'branch_id' => $attachment->branch_id,
                    'observation_id' => $attachment->observation_id, 'incident_log_id' => $attachment->incident_log_id,
                    'uploaded_by' => $attachment->uploaded_by, 'disk' => 'local', 'path' => $attachment->path,
                    'archive_path' => $archivePath, 'name' => $attachment->name, 'mime' => $attachment->mime,
                    'bytes' => $size, 'sha256' => $hash,
                ];
                $this->write($manifest, ($count ? ',' : '').json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $count++;
            }
            $this->write($manifest, '],"attachment_count":'.$count.',"file_count":'.$files.'}');
            fclose($manifest);
            $manifest = null;
            if (! $zip->addFile($manifestPath, 'manifest.json')
                || ! $zip->addFromString('README.txt', "RoseWatch attachment backup\n\nContains registered screenshots and uploaded incident files from all branches.\nmanifest.json maps attachment IDs and original local storage paths to ZIP entries, with SHA-256 checksums.\nKeep this ZIP with a SQL backup from the same backup session.\nFor recovery, restore SQL separately, then copy attachments/local/evidence/ to the application's private evidence/ directory, preserving filenames.\nExternal signed-document links are stored in SQL; their remote files are not downloaded.\nThis ZIP excludes .env, sessions, logs and unrelated private storage.\nThe SQL and ZIP exports are separate snapshots; avoid uploads/deletions while preparing the pair.\n")) {
                throw new BackupException('The attachment ZIP could not be completed. Please try again.');
            }
            $closed = $zip->close();
            $opened = false;
            clearstatcache(true, $path);
            if (! $closed || ! is_file($path) || filesize($path) === 0) {
                throw new BackupException('The attachment ZIP could not be completed. No download was created. Please try again.');
            }

            return [
                'path' => $path,
                'name' => 'RoseWatch-attachments-'.now('Asia/Manila')->format('Y-m-d_His').'-'.substr($id, 0, 8).'.zip',
                'bytes' => filesize($path), 'attachments' => $count, 'files' => $files,
            ];
        } catch (Throwable $exception) {
            if ($opened) {
                try {
                    @$zip->close();
                } catch (Throwable) {
                    // Preserve the original failure and still remove all temporary files.
                }
            }
            if ($path && is_file($path)) {
                @unlink($path);
            }
            throw $exception;
        } finally {
            if (is_resource($manifest)) {
                fclose($manifest);
            }
            if ($manifestPath && is_file($manifestPath)) {
                @unlink($manifestPath);
            }
            $lock->release();
        }
    }

    private function source(Attachment $attachment): string
    {
        $path = $attachment->path;
        if ($attachment->disk !== 'local' || ! is_string($path)
            || ! preg_match('/\Aevidence\/[A-Za-z0-9_.\/-]+\z/D', $path)
            || array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new BackupException('An attachment has an unsupported storage location. No ZIP was downloaded. Ask your administrator to check evidence storage.');
        }
        $root = realpath(Storage::disk('local')->path(''));
        if ($root === false) {
            throw new BackupException('Private evidence storage is unavailable.');
        }
        $source = $root;
        foreach (explode('/', $path) as $part) {
            $source .= DIRECTORY_SEPARATOR.$part;
            if (is_link($source)) {
                throw new BackupException('An attachment has an unsupported storage location. No ZIP was downloaded.');
            }
        }
        $resolved = realpath($source);
        $prefix = $root.DIRECTORY_SEPARATOR;
        $withinRoot = $resolved !== false && (PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($resolved), strtolower($prefix))
            : str_starts_with($resolved, $prefix));
        if (! $withinRoot || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new BackupException('One or more attachment files are missing or unreadable. No ZIP was downloaded. Ask your administrator to check evidence storage.');
        }

        return $resolved;
    }

    /** @return resource */
    private function privateFile(string $path)
    {
        $mask = umask(0077);
        try {
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new BackupException('Private attachment backup storage is unavailable.');
            }
            $stream = fopen($path, 'xb');
            if ($stream === false) {
                throw new BackupException('Private attachment backup storage is unavailable.');
            }
            chmod($path, 0600);

            return $stream;
        } finally {
            umask($mask);
        }
    }

    private function write($stream, string $contents): void
    {
        if (fwrite($stream, $contents) !== strlen($contents)) {
            throw new BackupException('Private attachment backup storage is full or unavailable.');
        }
    }
}
