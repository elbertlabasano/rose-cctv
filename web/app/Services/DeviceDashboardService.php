<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Models\Outage;
use App\Models\TicketRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DeviceDashboardService
{
    public function __construct(private AccessService $access) {}

    public function data(User $user, Device $device, array $filters): array
    {
        $this->access->branch($user, $device->branch_id);
        $tab = $filters['tab'] ?? 'overview';
        abort_if($tab === 'activity' && $user->role !== 'admin', 403);

        $today = CarbonImmutable::today();
        $from = CarbonImmutable::parse($filters['from'] ?? $today->subDays(29)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? $today->toDateString())->startOfDay();
        $device->load('branch');

        $observations = Observation::where('device_id', $device->id);
        $tickets = TicketRequest::whereHas('observation', fn (Builder $query) => $query->where('device_id', $device->id));
        $incidents = IncidentReport::where('device_id', $device->id)->where('branch_id', $device->branch_id);
        $outages = Outage::where('device_id', $device->id);
        $evidence = Attachment::where('branch_id', $device->branch_id)->where(function (Builder $query) use ($device) {
            $query->whereHas('observation', fn (Builder $related) => $related->where('device_id', $device->id))
                ->orWhereHas('incidentLog.incidentReport', fn (Builder $related) => $related->where('device_id', $device->id)->where('branch_id', $device->branch_id));
        });
        $activity = AuditLog::where('subject_type', 'Device')->where('subject_id', $device->id)->where('branch_id', $device->branch_id);
        $statusChanges = (clone $activity)->whereIn('changes->after->status', ['online', 'offline', 'degraded', 'maintenance', 'unknown'])
            ->where(fn ($query) => $query->whereNull('changes->before->status')
                ->orWhereColumn('changes->before->status', '!=', 'changes->after->status'));

        $counts = [
            'status' => (clone $observations)->count(),
            'status_changes' => (clone $statusChanges)->count(),
            'tickets' => (clone $tickets)->count(),
            'confirmed_tickets' => (clone $tickets)->where('status', 'created')->whereNotNull('external_url')->count(),
            'incidents' => (clone $incidents)->count(),
            'active_incidents' => (clone $incidents)->whereIn('status', ['assigned', 'in_progress', 'on_hold'])->count(),
            'evidence' => (clone $evidence)->count(),
            'outages' => (clone $outages)->count(),
            'open_outages' => (clone $outages)->whereNull('restored_at')->count(),
        ];
        if ($user->role === 'admin') {
            $counts['activity'] = (clone $activity)->count();
        }

        $history = [];
        // Only load the selected history, with a bounded page size. Histories span all dates.
        if ($tab === 'status') {
            $history['status'] = $observations->with(['checker:id,name', 'attachments', 'ticket'])
                ->orderByDesc('date')->orderByDesc('id')->paginate(12)->withQueryString()->through(fn ($item) => $this->observation($item));
            // Readers can see status transitions, but not the surrounding admin audit payload/reason.
            $history['status_changes'] = $statusChanges->with('user:id,name')->latest('id')
                ->paginate(12, ['*'], 'changes_page')->withQueryString()->through(fn ($item) => [
                    'id' => $item->id,
                    'from' => data_get($item->changes, 'before.status'),
                    'to' => data_get($item->changes, 'after.status'),
                    'actor' => $item->user?->name ?? 'Former user',
                    'created_at' => $item->created_at->toIso8601String(),
                ]);
        } elseif ($tab === 'tickets') {
            $history['tickets'] = $tickets->with(['observation.checker:id,name', 'observation.attachments', 'observation.ticket'])
                ->latest('id')->paginate(12)->withQueryString()->through(fn ($item) => [
                    ...$item->only('id', 'title', 'status', 'external_id', 'external_url', 'error'),
                    'created_at' => $item->created_at->toIso8601String(),
                    'observation' => $this->observation($item->observation),
                ]);
        } elseif ($tab === 'incidents') {
            $history['incidents'] = $incidents->with('assignee:id,name')->latest('id')->paginate(12)->withQueryString()->through(fn ($item) => [
                ...$item->only('id', 'reference', 'title', 'description', 'status', 'priority', 'category', 'signed_document_url', 'resolution'),
                'created_at' => $item->created_at->toIso8601String(),
                'assignee' => $item->assignee?->name,
                'url' => url('/incidents').'?'.http_build_query(['branch_id' => $device->branch_id, 'incident_id' => $item->id]),
            ]);
        } elseif ($tab === 'evidence') {
            $history['evidence'] = $evidence->with(['observation:id,date', 'incidentLog.incidentReport:id,reference'])
                ->latest('id')->paginate(12)->withQueryString()->through(fn ($item) => [
                    ...$this->attachment($item),
                    'source' => $item->observation_id ? 'Daily record' : 'Incident evidence',
                    'record_date' => $item->observation?->date->toDateString(),
                    'incident_reference' => $item->incidentLog?->incidentReport?->reference,
                ]);
        } elseif ($tab === 'outages') {
            $history['outages'] = $outages->latest('started_at')->paginate(12)->withQueryString()->through(fn ($item) => [
                'id' => $item->id,
                'started_at' => $item->started_at->toIso8601String(),
                'restored_at' => $item->restored_at?->toIso8601String(),
                'minutes' => round(max(0, $item->started_at->diffInMinutes($item->restored_at ?? now(), false)), 1),
                'notes' => $item->notes,
            ]);
        } elseif ($tab === 'activity') {
            $history['activity'] = $activity->with('user:id,name')->latest('id')->paginate(12)->withQueryString()->through(fn ($item) => [
                ...$item->only('id', 'action', 'reason'),
                'actor' => $item->user?->name ?? 'System',
                'created_at' => $item->created_at->toIso8601String(),
            ]);
        }

        $parent = $device->parent()->where('branch_id', $device->branch_id)->first();
        $related = fn (Device $item) => $item->only('id', 'asset_id', 'name', 'type', 'status');

        return [
            'device' => $device,
            'parent' => $parent ? $related($parent) : null,
            'children' => $device->children()->where('branch_id', $device->branch_id)->orderBy('asset_id')->get()->map($related),
            'today' => $today->toDateString(),
            'tab' => $tab,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'counts' => $counts,
            'days' => $tab === 'overview' ? $this->trends($device, $from, $to) : [],
            'history' => (object) $history,
            'can_audit' => $user->role === 'admin',
            'can_check' => $this->access->write($user) && in_array($device->type, ['IP Camera', 'Analog Camera']),
        ];
    }

    private function attachment(Attachment $attachment): array
    {
        return [
            ...$attachment->only('id', 'name', 'mime'),
            'url' => route('attachments.show', $attachment),
            'download_url' => route('attachments.download', $attachment),
            'created_at' => $attachment->created_at->toIso8601String(),
        ];
    }

    private function observation(Observation $observation): array
    {
        return [
            'id' => $observation->id,
            'device_id' => $observation->device_id,
            'date' => $observation->date->toDateString(),
            'status' => $observation->status,
            'check_result' => $observation->check_result,
            'import_source' => $observation->import_source,
            'import_reference' => $observation->import_reference,
            'is_checked' => $observation->hasStatusCheck(),
            'notes' => $observation->notes,
            'checked_at' => $observation->checked_at?->toIso8601String(),
            'checker' => $observation->checker?->name,
            'attachments' => $observation->attachments->map(fn ($item) => $this->attachment($item)),
            'ticket' => $observation->ticket?->only('id', 'title', 'status', 'external_id', 'external_url', 'error'),
        ];
    }

    private function trends(Device $device, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = CarbonImmutable::now();
        $end = $to->endOfDay()->min($now);
        $records = $device->observations()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with(['checker:id,name', 'attachments', 'ticket'])->get()->keyBy(fn ($item) => $item->date->toDateString());
        $outages = Outage::where('device_id', $device->id)->where('started_at', '<', $end)
            ->where(fn ($query) => $query->whereNull('restored_at')->orWhere('restored_at', '>', $from))
            ->orderBy('started_at')->get();

        // Merge recorded overlapping intervals before calculating downtime, avoiding double counting.
        $intervals = [];
        foreach ($outages as $outage) {
            $start = $outage->started_at->max($from);
            $finish = ($outage->restored_at ?? $now)->min($end);
            if ($finish->lte($start)) {
                continue;
            }
            $last = array_key_last($intervals);
            if ($last !== null && $start->lte($intervals[$last][1])) {
                $intervals[$last][1] = $finish->max($intervals[$last][1]);
            } else {
                $intervals[] = [$start, $finish];
            }
        }

        $days = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $record = $records->get($day->toDateString());
            $minutes = 0;
            foreach ($intervals as [$start, $finish]) {
                $overlapStart = $start->max($day);
                $overlapEnd = $finish->min($day->addDay());
                if ($overlapEnd->gt($overlapStart)) {
                    $minutes += $overlapStart->diffInMinutes($overlapEnd);
                }
            }
            $days[] = [
                'date' => $day->toDateString(),
                'status' => $record?->displayStatus() ?? 'unchecked',
                'downtime_minutes' => round($minutes, 1),
                'record' => $record ? $this->observation($record) : null,
            ];
        }

        return $days;
    }
}
