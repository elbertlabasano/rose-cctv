<?php

namespace App\Services;

use App\Jobs\CreateExternalTicket;
use App\Models\Device;
use App\Models\Observation;
use App\Models\TicketRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ObservationService
{
    public function __construct(private AccessService $access, private AttachmentService $files, private DeviceService $devices, private AuditService $audit) {}

    public function save(User $actor, array $data): void
    {
        if (($data['date'] ?? null) !== now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Daily actions are allowed only for today in Philippine time. Past records are read-only.']);
        }
        if (! $data['update_status'] && ! $data['attach_images'] && ! $data['create_ticket']) {
            $this->fail('Select at least one action.');
        }
        if (count($data['device_ids']) > 1 && ($data['attach_images'] || $data['create_ticket'])) {
            $this->fail('Bulk updates support status only; use an individual record for evidence or tickets.');
        }
        if ($data['update_status'] && empty($data['status'])) {
            $this->fail('Choose Online or Offline.');
        }
        if ($data['create_ticket'] && empty($data['ticket_title'])) {
            $this->fail('Enter a ticket title.');
        }
        $this->files->transaction(function () use ($actor, $data) {
            foreach (collect($data['device_ids'])->sort()->values() as $id) {
                $device = Device::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->access->branch($actor, $device->branch_id);
                if (! in_array($device->type, ['IP Camera', 'Analog Camera'])) {
                    $this->fail('Daily camera checks only apply to cameras.');
                }
                $record = Observation::firstOrCreate(['device_id' => $id, 'date' => $data['date']]);
                if ($data['update_status']) {
                    $record->status = $data['status'];
                    $record->checked_at = now();
                    $record->checked_by = $actor->id;
                    // Historical corrections do not rewrite the present physical state or outage timestamps.
                    if ($data['date'] === now()->toDateString()) {
                        $device->update(['status' => $data['status'], 'status_observed_at' => now()]);
                        $this->devices->transition($device, $data['status'], CarbonImmutable::now(), $data['notes']);
                    }
                }
                $record->notes = $data['notes'];
                $record->save();
                if ($data['attach_images']) {
                    $existingCount = $record->attachments()->count();
                    if (!$existingCount && empty($data['images'])) $this->fail('Capture or upload at least one image.');
                    $remove = $data['remove_attachment_ids'] ?? [];
                    $owned = $record->attachments()->whereIn('id', $remove)->get();
                    if ($owned->count() !== count($remove)) {
                        $this->fail('Only this daily record’s attachments may be removed.');
                    }
                    if ($record->attachments()->count() - count($remove) + count($data['images'] ?? []) > 6) {
                        $this->fail('Up to six images may be attached to a daily record.');
                    }
                    foreach ($owned as $attachment) {
                        $this->files->remove($attachment);
                    }
                    foreach ($data['images'] ?? [] as $file) {
                        $this->files->store($file, $device->branch_id, ['observation_id' => $record->id]);
                    }
                }
                if ($data['create_ticket']) {
                    $ticket = TicketRequest::firstOrCreate(['observation_id' => $record->id], ['requested_by' => $actor->id, 'title' => $data['ticket_title'], 'idempotency_key' => (string) Str::uuid(), 'payload' => ['camera_id' => $device->asset_id, 'camera_name' => $device->name, 'branch' => $device->branch->name, 'date' => $data['date'], 'status' => $record->status, 'notes' => $record->notes, 'title' => $data['ticket_title'], 'attachment_ids' => $record->attachments()->pluck('id')->all(), 'requested_by' => $actor->name]]);
                    if (! $ticket->external_url && ! in_array($ticket->status, ['processing', 'queued'])) {
                        $configured = (bool) config('tickets.endpoint');
                        $ticket->update(['status' => $configured ? 'queued' : 'unavailable', 'error' => $configured ? null : 'Ticket provider is not connected. Your record is saved; retry after configuration.']);
                        if ($configured) {
                            CreateExternalTicket::dispatch($ticket->id)->afterCommit();
                        }
                    }
                }
                $this->audit->record($record, 'Daily observation saved', $data['notes'], $device->branch_id, ['status' => $record->status, 'date' => $data['date']]);
            }
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['observation' => $message]);
    }
}
