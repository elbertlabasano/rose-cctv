<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;

class ManualBackupService
{
    public function __construct(private MysqlBackupService $database, private AttachmentBackupService $attachments) {}

    public function overview(): array
    {
        $folder = config('backups.google_drive_folder_id');

        return [
            'available' => $this->database->available(),
            'attachments_available' => $this->attachments->available(),
            'attachment_count' => Attachment::count(),
            'attachment_bytes' => (int) Attachment::sum('size'),
            'drive_folder_url' => is_string($folder) && preg_match('/\A[A-Za-z0-9_-]{10,200}\z/D', $folder)
                ? 'https://drive.google.com/drive/folders/'.$folder : null,
            'recent_exports' => AuditLog::whereIn('action', ['Database backup prepared', 'Attachment backup prepared'])->with('user:id,name')
                ->latest('id')->limit(10)->get()->map(fn ($audit) => [
                    'id' => $audit->id,
                    'type' => $audit->action === 'Attachment backup prepared' ? 'attachments' : 'database',
                    'actor' => $audit->user?->name ?? 'Former user',
                    'created_at' => $audit->created_at->toIso8601String(),
                    'filename' => $audit->changes['filename'] ?? null,
                    'bytes' => $audit->changes['bytes'] ?? null,
                ])->values(),
        ];
    }
}
