<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Device;
use App\Models\Followup;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\Outage;
use App\Models\User;
use Carbon\CarbonImmutable;

class WorkspaceService
{
    public function __construct(private AccessService $access) {}

    public function data(User $user, string $date): array
    {
        $branchIds = $this->access->branches($user)->pluck('id');
        $date = CarbonImmutable::parse($date);
        $start = $date->startOfMonth();
        $end = $date->endOfMonth();
        $devices = Device::whereIn('branch_id', $branchIds)->with('branch')->orderBy('asset_id')->get();
        $observations = Observation::whereIn('device_id', $devices->pluck('id'))->whereBetween('date', [$start->toDateString(), $end->toDateString()])->with(['attachments', 'checker:id,name', 'ticket'])->get();
        $incidents = IncidentReport::whereIn('branch_id', $branchIds)->with(['device:id,asset_id,name', 'branch:id,name', 'assignee:id,name', 'logs' => fn ($q) => $q->orderByDesc('id'), 'logs.user:id,name', 'logs.attachments'])->orderByDesc('id')->get();
        $people = User::where('active', true)->whereIn('role', ['admin', 'supervisor', 'operator', 'technician'])->where(function ($q) use ($branchIds) {
            $q->where('role', 'admin')->orWhere('all_branches', true)->orWhereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds));
        })->with('branches:id')->get(['id', 'name', 'role', 'all_branches']);
        $attachments = fn ($collection) => $this->attachments($collection);

        return [
            'today' => now()->toDateString(), 'date' => $date->toDateString(), 'branches' => $this->access->branches($user)->with('cluster')->orderBy('name')->get(),
            'clusters' => Cluster::when($user->role !== 'admin', fn ($q) => $q->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds)))->orderBy('name')->get(['id', 'name', 'code']),
            'devices' => $devices, 'observations' => $observations->map(fn ($record) => $this->observation($record)),
            'incidents' => $incidents->map(function ($i) use ($attachments) {
                $data = $i->toArray();
                $data['logs'] = $i->logs->map(fn ($l) => ['id' => $l->id, 'action' => $l->action, 'notes' => $l->notes, 'details' => $l->details, 'actor' => $l->user?->name, 'created_at' => $l->created_at->toIso8601String(), 'attachments' => $attachments($l->attachments)]);

                return $data;
            }),
            'outages' => Outage::whereIn('device_id', $devices->pluck('id'))->orderByDesc('started_at')->get(),
            'people' => $people, 'followups' => Followup::where('user_id', $user->id)->get(),
            'accounts' => $user->role === 'admin' ? User::with('branches:id,name')->orderBy('name')->get() : [],
            'audits' => $user->role === 'admin' ? AuditLog::with('user:id,name')->latest('id')->limit(200)->get() : [],
            'permissions' => ['write' => $this->access->write($user), 'manage' => $this->access->manage($user), 'verify' => $this->access->verify($user), 'admin' => $user->role === 'admin'],
            'ticket_configured' => app(TicketIntegration::class)->canCreate(),
            'ticket_view_configured' => app(TicketIntegration::class)->canView(),
            'ticket_boards' => config('tickets.boards', []),
            'ticket_default_board' => config('tickets.board_id') ? (int) config('tickets.board_id') : null,
        ];
    }

    public function observation(Observation $record): array
    {
        $record->loadMissing(['attachments', 'checker:id,name', 'ticket']);

        return [
            'id' => $record->id, 'device_id' => $record->device_id, 'date' => $record->date->format('Y-m-d'),
            'status' => $record->status, 'check_result' => $record->check_result,
            'import_source' => $record->import_source, 'import_reference' => $record->import_reference,
            'is_checked' => $record->hasStatusCheck(), 'notes' => $record->notes,
            'checked_at' => $record->checked_at?->toIso8601String(), 'checker' => $record->checker?->name,
            'attachments' => $this->attachments($record->attachments),
            'ticket' => $record->ticket?->only('id', 'title', 'status', 'external_id', 'external_url', 'error'),
        ];
    }

    private function attachments($collection)
    {
        return $collection->map(fn ($attachment) => [
            'id' => $attachment->id, 'name' => $attachment->name, 'mime' => $attachment->mime,
            'url' => route('attachments.show', $attachment), 'download_url' => route('attachments.download', $attachment),
            'created_at' => $attachment->created_at->toIso8601String(),
        ])->values();
    }
}
