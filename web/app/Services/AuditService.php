<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(Model $subject, string $action, string $reason, ?int $branchId = null, array $changes = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'branch_id' => $branchId, 'subject_type' => class_basename($subject), 'subject_id' => $subject->id, 'action' => $action, 'reason' => $reason, 'changes' => $changes]);
    }
}
