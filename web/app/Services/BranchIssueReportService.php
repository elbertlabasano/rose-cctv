<?php

namespace App\Services;

use App\Models\Cluster;
use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\Outage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BranchIssueReportService
{
    public function __construct(private AccessService $access) {}

    public function generate(User $user, array $input): array
    {
        $date = $input['date'] ?? now('Asia/Manila')->toDateString();
        $cutoff = CarbonImmutable::parse($date, 'Asia/Manila')->endOfDay()->min(CarbonImmutable::now('Asia/Manila'));
        $cluster = $input['cluster_id'] ?? 'all';
        $allBranches = $this->access->branches($user)->with('cluster:id,name,code')->orderBy('name')->get();
        $branches = $allBranches->filter(fn ($branch) => match ($cluster) {
            'all' => true,
            'clustered' => $branch->cluster_id !== null,
            'unassigned' => $branch->cluster_id === null,
            default => (string) $branch->cluster_id === $cluster,
        });
        if (! in_array($cluster, ['all', 'clustered', 'unassigned'], true)) {
            abort_unless($user->role === 'admin' || $branches->isNotEmpty(), 403);
        }
        if (! empty($input['branch_id'])) {
            $this->access->branch($user, (int) $input['branch_id']);
            if (! $branches->contains('id', (int) $input['branch_id'])) {
                throw ValidationException::withMessages(['branch_id' => 'Choose a branch in the selected cluster.']);
            }
            $branches = $branches->where('id', (int) $input['branch_id']);
        }

        $devices = Device::whereIn('branch_id', $branches->pluck('id'))
            ->with('branch.cluster')->orderBy('asset_id')->get();
        $ids = $devices->pluck('id');
        $records = Observation::whereIn('device_id', $ids)->where('date', '<=', $date)
            ->where(fn ($q) => $q->whereNotNull('checked_at')->orWhereNotNull('import_source'))
            ->whereNotNull('status')->orderByDesc('date')->get()->unique('device_id')->keyBy('device_id');
        $incidents = IncidentReport::whereIn('device_id', $ids)->whereIn('branch_id', $branches->pluck('id'))
            ->where('created_at', '<=', $cutoff)->with(['logs' => fn ($q) => $q->where('created_at', '<=', $cutoff)->orderBy('created_at')->orderBy('id')])
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('device_id');
        $outages = Outage::whereIn('device_id', $ids)->where('started_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('restored_at')->orWhere('restored_at', '>', $cutoff))
            ->orderByDesc('started_at')->get()->unique('device_id')->keyBy('device_id');

        $rows = collect();
        foreach ($devices as $device) {
            $record = $records->get($device->id);
            $status = $record?->status ?? 'unchecked';
            $statusDate = $record?->date->toDateString();
            $recordAt = $record?->checked_at ?? $record?->date->startOfDay();
            $fromInventory = false;
            // An inventory status is usable only if its observation time is known and not in the future.
            $deviceAt = $device->status_reported_on?->startOfDay() ?? $device->status_observed_at;
            if ($deviceAt && $deviceAt->lte($cutoff)
                && (! $recordAt || $deviceAt->gt($recordAt))) {
                $status = $device->status;
                $statusDate = $deviceAt->toDateString();
                $fromInventory = true;
            }
            $outage = $status === 'offline' ? $outages->get($device->id) : null;
            $base = [
                'branch_id' => $device->branch_id, 'branch' => $device->branch->name,
                'cluster' => $device->branch->cluster?->name ?? 'Unassigned',
                'device_id' => $device->id, 'asset_id' => $device->asset_id, 'device' => $device->name,
                'status' => $status, 'status_date' => $statusDate,
                'days_offline' => $outage ? (int) $outage->started_at->startOfDay()->diffInDays($cutoff->startOfDay()) + 1 : null,
            ];
            $hasActive = false;
            foreach ($incidents->get($device->id, collect()) as $incident) {
                $state = $this->incidentState($incident, $cutoff);
                $resolved = in_array($state['status'], ['resolved', 'closed'], true);
                if ($resolved && (empty($input['include_resolved']) || $state['resolved_on'] !== $date)) {
                    continue;
                }
                $hasActive = $hasActive || ! $resolved;
                $latest = $incident->logs->filter(fn ($log) => $log->action !== 'document' && filled($log->notes))->last();
                $remarks = collect([$incident->title, $latest?->notes ?: $incident->description])->filter()->unique()->implode(' — ');
                $rows->push([...$base,
                    'reference' => $incident->reference, 'incident_id' => $incident->id,
                    'issue_state' => $state['status'], 'remarks' => $remarks,
                    'concern_on' => $incident->created_at->toDateString(), 'resolved_on' => $resolved ? $state['resolved_on'] : null,
                ]);
            }
            if (! $hasActive && in_array($status, ['offline', 'degraded', 'maintenance'], true)) {
                $rows->push([...$base,
                    'reference' => null, 'incident_id' => null, 'issue_state' => 'camera_status',
                    'remarks' => (! $fromInventory && filled($record?->notes)) ? $record->notes : ucfirst($status).' recorded. No active incident report is linked.',
                    'concern_on' => $outage?->started_at->toDateString(), 'resolved_on' => null,
                ]);
            }
        }
        $rows = $rows->sortBy(fn ($row) => mb_strtolower($row['branch']).'|'.$row['asset_id'].'|'.$row['concern_on'])->values();

        return [
            'date' => $date, 'today' => now('Asia/Manila')->toDateString(), 'generated_at' => now('Asia/Manila')->format('Y-m-d H:i').' PHT',
            'scope' => ! empty($input['branch_id']) ? $branches->first()?->name : (Cluster::find(ctype_digit($cluster) ? (int) $cluster : 0)?->name ?? match ($cluster) {
                'clustered' => 'Assigned clusters', 'unassigned' => 'Unassigned branches', default => 'All assigned branches',
            }),
            'filters' => ['cluster_id' => $cluster, 'branch_id' => (string) ($input['branch_id'] ?? 'all'), 'include_resolved' => ! empty($input['include_resolved'])],
            'branches' => $allBranches->map(fn ($branch) => $branch->only('id', 'name', 'cluster_id'))->values(),
            'clusters' => Cluster::whereIn('id', $allBranches->pluck('cluster_id')->filter())->orderBy('name')->get(['id', 'name', 'code']),
            'rows' => $rows, 'summary' => ['branches' => $rows->pluck('branch_id')->unique()->count(), 'devices' => $rows->pluck('device_id')->unique()->count(), 'issues' => $rows->count()],
        ];
    }

    private function incidentState(IncidentReport $incident, CarbonImmutable $cutoff): array
    {
        $events = collect();
        if ($incident->resolved_at && $incident->resolved_at->lte($cutoff)) {
            $events->push(['at' => $incident->resolved_at, 'status' => 'resolved']);
        }
        if ($incident->verified_at && $incident->verified_at->lte($cutoff)) {
            $events->push(['at' => $incident->verified_at, 'status' => 'closed']);
        }
        // Explicit action logs win when an action and a saved timestamp share the same second.
        $events = $events->concat($incident->logs->filter(fn ($log) => filled($log->details['to'] ?? null))
            ->map(fn ($log) => ['at' => $log->created_at, 'status' => $log->details['to']]));
        $last = $events->sortBy(fn ($event) => $event['at']->format('Y-m-d H:i:s.u'))->last();
        $status = $last['status'] ?? ($incident->updated_at->lte($cutoff) ? $incident->status : 'assigned');
        $resolved = $events->where('status', 'resolved')->sortBy(fn ($event) => $event['at']->format('Y-m-d H:i:s.u'))->last();

        return ['status' => $status, 'resolved_on' => $resolved ? $resolved['at']->toDateString() : null];
    }
}
