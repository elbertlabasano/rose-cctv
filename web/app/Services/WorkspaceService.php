<?php

namespace App\Services;

use App\Models\AuditLog;
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
        $attachments = fn ($collection) => $collection->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'mime' => $a->mime, 'url' => route('attachments.show', $a), 'download_url' => route('attachments.download', $a), 'created_at' => $a->created_at->toIso8601String()])->values();

        return [
            'today' => now()->toDateString(), 'date' => $date->toDateString(), 'branches' => $this->access->branches($user)->orderBy('name')->get(),
            'devices' => $devices, 'observations' => $observations->map(function ($o) use ($attachments) {
                return ['id' => $o->id, 'device_id' => $o->device_id, 'date' => $o->date->format('Y-m-d'), 'status' => $o->status, 'notes' => $o->notes, 'checked_at' => $o->checked_at?->toIso8601String(), 'checker' => $o->checker?->name, 'attachments' => $attachments($o->attachments), 'ticket' => $o->ticket?->only('id', 'title', 'status', 'external_id', 'external_url', 'error')];
            }),
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
            'ticket_configured' => (bool) config('tickets.endpoint'),
        ];
    }
}
