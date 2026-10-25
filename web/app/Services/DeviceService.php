<?php

namespace App\Services;

use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Outage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceService
{
    public function __construct(private AccessService $access, private AuditService $audit) {}

    public function save(User $actor, array $data, ?Device $device = null): Device
    {
        $this->access->branch($actor, (int) $data['branch_id']);
        if ($device) {
            $this->access->branch($actor, $device->branch_id);
        }

        return DB::transaction(function () use ($data, $device) {
            $old = $device ? Device::whereKey($device->id)->lockForUpdate()->firstOrFail() : null;
            $device = $old;
            if ($old && $old->asset_id !== $data['asset_id']) {
                $this->fail('Asset IDs cannot be changed.');
            }
            if ($old && $old->branch_id != $data['branch_id'] && $old->children()->exists()) {
                $this->fail('Move connected equipment before changing the branch.');
            }
            if ($old && $old->branch_id != $data['branch_id'] && ($old->observations()->exists() || $old->status_observed_at || IncidentReport::where('device_id', $old->id)->exists())) {
                $this->fail('Devices with operational history cannot change branch. Register a new asset instead.');
            }
            $recorder = in_array($data['type'], ['DVR', 'NVR']);
            if ($recorder && empty($data['channels'])) {
                $this->fail('Enter recorder channel capacity.');
            }
            $parent = ! empty($data['parent_id']) ? Device::whereKey($data['parent_id'])->lockForUpdate()->first() : null;
            if ($parent) {
                if ($parent->branch_id != $data['branch_id'] || $parent->id === $old?->id) {
                    $this->fail('Choose a connected device in the same branch.');
                }
                $cursor = $parent;
                $visited = [];
                while ($cursor) {
                    if (in_array($cursor->id, $visited) || $cursor->id === $old?->id) {
                        $this->fail('Circular connections are not allowed.');
                    } $visited[] = $cursor->id;
                    $cursor = $cursor->parent;
                }
            }
            if (! empty($data['channel']) && (! $parent || ! in_array($parent->type, ['DVR', 'NVR']) || ! in_array($data['type'], ['IP Camera', 'Analog Camera']) || $data['channel'] > $parent->channels)) {
                $this->fail('Choose a valid recorder channel for this camera.');
            }
            if ($old && $old->children()->whereNotNull('channel')->exists() && (! $recorder || $old->children()->max('channel') > ($data['channels'] ?? 0))) {
                $this->fail('Capacity must cover all assigned camera channels.');
            }
            if (! empty($data['ip']) && Device::where('branch_id', $data['branch_id'])->where('ip', $data['ip'])->when($old, fn ($q) => $q->whereKeyNot($old->id))->exists()) {
                $this->fail('This IP address is already used in this branch.');
            }
            if ($parent && ! empty($data['channel']) && Device::where('parent_id', $parent->id)->where('channel', $data['channel'])->when($old, fn ($q) => $q->whereKeyNot($old->id))->exists()) {
                $this->fail('This recorder channel is already assigned.');
            }
            $reason = $data['reason'];
            unset($data['reason']);
            if (! $recorder) {
                $data['channels'] = null;
                $data['storage_tb'] = null;
                $data['disk_health'] = null;
            }
            $device ??= new Device;
            $before = $device->exists ? $device->only(array_keys($data)) : [];
            $changed = ! $device->exists || $device->status !== $data['status'];
            $device->fill($data);
            if ($changed) {
                $device->status_observed_at = now();
            }
            $device->save();
            if ($changed) {
                $this->transition($device, $data['status'], CarbonImmutable::now(), $reason);
            }
            $this->audit->record($device, $old ? 'Device updated' : 'Device registered', $reason, $device->branch_id, ['before' => $before, 'after' => $device->only(array_keys($data))]);

            return $device;
        });
    }

    public function transition(Device $device, string $status, CarbonImmutable $at, string $notes): void
    {
        $open = Outage::where('device_id', $device->id)->whereNull('restored_at')->lockForUpdate()->first();
        if ($status === 'offline' && ! $open) {
            Outage::create(['device_id' => $device->id, 'started_at' => $at, 'notes' => $notes]);
        }
        if ($status === 'online' && $open && $at->gte($open->started_at)) {
            $open->update(['restored_at' => $at]);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['device' => $message]);
    }
}
