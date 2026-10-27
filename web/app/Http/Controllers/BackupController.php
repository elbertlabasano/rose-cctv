<?php

namespace App\Http\Controllers;

use App\Exceptions\BackupException;
use App\Http\Requests\BackupRequest;
use App\Services\AttachmentBackupService;
use App\Services\AuditService;
use App\Services\ManualBackupService;
use App\Services\MysqlBackupService;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Throwable;

class BackupController extends Controller
{
    public function attachments(BackupRequest $request, AttachmentBackupService $attachments, AuditService $audit)
    {
        $backup = null;
        try {
            $backup = $attachments->create();
            $audit->record($request->user(), 'Attachment backup prepared', 'Manual attachment ZIP prepared for download.', null, [
                'filename' => $backup['name'], 'bytes' => $backup['bytes'],
                'attachments' => $backup['attachments'], 'files' => $backup['files'],
            ]);

            return response()->download($backup['path'], $backup['name'], [
                'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend();
        } catch (Throwable $exception) {
            if ($backup && is_file($backup['path'])) {
                @unlink($backup['path']);
            }
            Log::warning('Manual attachment backup failed.', ['exception_class' => $exception::class]);

            $message = $exception instanceof BackupException
                ? $exception->getMessage()
                : 'The attachment ZIP could not be prepared. No download was created. Please contact your administrator.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503)->header('Cache-Control', 'private, no-store');
            }

            return redirect()->route('backups')->with('error', $message);
        }
    }

    public function index(BackupRequest $request, ManualBackupService $backups)
    {
        return Inertia::render('Backups/Index', [
            'backups' => $backups->overview(),
            'csrf_token' => csrf_token(),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function download(BackupRequest $request, MysqlBackupService $database, AuditService $audit)
    {
        $backup = null;
        try {
            $backup = $database->create();
            $audit->record($request->user(), 'Database backup prepared', 'Manual SQL backup prepared for download.', null, [
                'filename' => $backup['name'], 'bytes' => $backup['bytes'],
            ]);

            return response()->download($backup['path'], $backup['name'], [
                'Content-Type' => 'application/sql', 'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend();
        } catch (Throwable $exception) {
            if ($backup && is_file($backup['path'])) {
                @unlink($backup['path']);
            }
            // Process errors can contain private connection details. Never log their messages/output.
            $context = ['exception_class' => $exception::class];
            if ($exception instanceof BackupException) {
                foreach (['exit_code', 'mysql_error', 'socket_error'] as $key) {
                    if (is_int($exception->diagnostics[$key] ?? null)) {
                        $context[$key] = $exception->diagnostics[$key];
                    }
                }
            }
            Log::warning('Manual database backup failed.', $context);

            return redirect()->route('backups')->with('error', $exception instanceof BackupException
                ? $exception->getMessage()
                : 'The SQL backup could not be prepared. Please contact your administrator.');
        }
    }
}
