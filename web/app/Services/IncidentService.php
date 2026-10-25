<?php

namespace App\Services;

use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentService
{
    public function __construct(private AccessService $access, private AttachmentService $files, private AuditService $audit) {}

    private function assignee(?int $id, int $branchId): void
    {
        if (! $id) {
            return;
        }
        $user = User::findOrFail($id);
        if (! $this->access->write($user) || ! $this->access->branches($user)->whereKey($branchId)->exists()) {
            $this->fail('Choose an active assignee with access to this branch.');
        }
    }

    public function create(User $actor, array $data): IncidentReport
    {
        $device = Device::findOrFail($data['device_id']);
        $this->access->branch($actor, $device->branch_id);
        $this->assignee($data['assigned_to'] ?? null, $device->branch_id);

        return $this->files->transaction(function () use ($actor, $data, $device) {
            $incident = IncidentReport::create([...$data, 'reference' => 'IR-'.strtoupper((string) Str::ulid()), 'branch_id' => $device->branch_id, 'created_by' => $actor->id, 'status' => 'assigned', 'due_at' => now()->addHours(['critical' => 4, 'high' => 24, 'medium' => 72, 'low' => 120][$data['priority']])]);
            $incident->logs()->create(['user_id' => $actor->id, 'action' => 'Incident created', 'notes' => ($data['description'] ?? null) ?: $data['title']]);
            $this->audit->record($incident, 'Incident created', $data['title'], $device->branch_id);

            return $incident;
        });
    }

    public function act(User $actor, IncidentReport $incident, array $data): void
    {
        $this->access->branch($actor, $incident->branch_id);
        $this->files->transaction(function () use ($actor, $incident, $data) {
            $incident = IncidentReport::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $before = $incident->status;
            $action = $data['action'];
            $active = in_array($before, ['assigned', 'in_progress', 'on_hold']);
            if ($action === 'resolve') {
                if (! $active) {
                    $this->fail('Only active incidents can be resolved.');
                }
                if (empty($data['confirmed']) || empty($data['cause']) || empty($data['work']) || empty($data['restored_at'])) {
                    $this->fail('Provide root cause, work performed, restoration time and confirmation.');
                }
                $restored = CarbonImmutable::parse($data['restored_at']);
                if ($restored->lt($incident->created_at) || $restored->gt(now())) {
                    $this->fail('Restoration must be between incident creation and now.');
                }
                $incident->fill(['status' => 'resolved', 'restored_at' => $restored, 'resolved_at' => now(), 'verified_at' => null, 'resolution' => ['cause' => $data['cause'], 'work' => $data['work'], 'prevention' => $data['prevention'] ?? null, 'resolved_by' => $actor->id]]);
            } elseif ($action === 'verify') {
                abort_unless($this->access->verify($actor), 403);
                if ($before !== 'resolved' || empty($data['confirmed'])) {
                    $this->fail('Resolve the incident and confirm the verification before closing.');
                }
                $incident->fill(['status' => 'closed', 'verified_at' => now()]);
            } elseif ($action === 'reopen') {
                if (! in_array($before, ['resolved', 'closed'])) {
                    $this->fail('Only resolved or closed incidents can be reopened.');
                }
                $incident->fill(['status' => 'in_progress', 'verified_at' => null]);
            } elseif ($action === 'document') {
                $incident->signed_document_url = $data['signed_document_url'] ?? null;
            } else {
                if (! $active) {
                    $this->fail('Reopen the incident before adding a work update.');
                }
                $incident->status = $data['status'] ?? $before;
                $this->assignee($data['assigned_to'] ?? null, $incident->branch_id);
                if (array_key_exists('assigned_to', $data)) {
                    $incident->assigned_to = $data['assigned_to'];
                }
            }
            $incident->save();
            $log = $incident->logs()->create(['user_id' => $actor->id, 'action' => $action, 'notes' => $data['notes'], 'details' => ['from' => $before, 'to' => $incident->status, 'resolution' => $action === 'resolve' ? $incident->resolution : null, 'signed_document_url' => $action === 'document' ? $incident->signed_document_url : null]]);
            foreach ($data['proofs'] ?? [] as $file) {
                $this->files->store($file, $incident->branch_id, ['incident_log_id' => $log->id]);
            }
            $this->audit->record($incident, 'Incident '.$action, $data['notes'], $incident->branch_id, ['from' => $before, 'to' => $incident->status]);
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['incident' => $message]);
    }
}
