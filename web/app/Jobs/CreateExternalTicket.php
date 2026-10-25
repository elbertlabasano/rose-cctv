<?php

namespace App\Jobs;

use App\Contracts\TicketProvider;
use App\Models\Attachment;
use App\Models\TicketRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateExternalTicket implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $ticketId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TicketProvider $provider): void
    {
        $ticket = DB::transaction(function () {
            $ticket = TicketRequest::whereKey($this->ticketId)->lockForUpdate()->firstOrFail();
            if ($ticket->external_url || ($ticket->status === 'processing' && $ticket->processing_at?->gt(now()->subMinutes(2)))) {
                return null;
            }
            $ticket->update(['status' => 'processing', 'processing_at' => now(), 'error' => null]);

            return $ticket;
        });
        if (! $ticket) {
            return;
        }
        try {
            $payload = $ticket->payload;
            $payload['screenshots'] = [];
            foreach ($payload['attachment_ids'] ?? [] as $id) {
                $file = Attachment::where('observation_id', $ticket->observation_id)->findOrFail($id);
                $payload['screenshots'][] = ['name' => $file->name, 'mime' => $file->mime, 'data' => base64_encode(Storage::disk($file->disk)->get($file->path))];
            }
            unset($payload['attachment_ids']);
            $result = $provider->create($payload, $ticket->idempotency_key);
            if (empty($result['id']) || ! filter_var($result['url'] ?? null, FILTER_VALIDATE_URL) || parse_url($result['url'], PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('Invalid provider confirmation.');
            }
            $ticket->update(['status' => 'created', 'external_id' => $result['id'], 'external_url' => $result['url'], 'processing_at' => null, 'error' => null]);
        } catch (\Throwable $error) {
            $ticket->update(['status' => 'failed', 'processing_at' => null, 'error' => 'Provider confirmation unavailable. Retry uses the same request key.']);
            throw $error;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        TicketRequest::whereKey($this->ticketId)->whereNull('external_url')->update(['status' => 'failed', 'processing_at' => null, 'error' => 'Ticket request failed. Review the integration and retry.']);
    }
}
