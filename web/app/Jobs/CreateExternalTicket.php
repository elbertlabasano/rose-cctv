<?php

namespace App\Jobs;

use App\Contracts\TicketProvider;
use App\Exceptions\TicketProviderException;
use App\Models\Attachment;
use App\Models\TicketRequest;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CreateExternalTicket implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $ticketId)
    {
        $this->onQueue('tickets');
    }

    public function handle(TicketProvider $provider): void
    {
        $ticket = DB::transaction(function () {
            $ticket = TicketRequest::whereKey($this->ticketId)->lockForUpdate()->firstOrFail();
            // Never reclaim processing or uncertain requests: the provider cannot deduplicate POSTs.
            if ($ticket->external_url || $ticket->status !== 'queued') {
                return null;
            }
            $ticket->update(['status' => 'processing', 'processing_at' => now(), 'error' => null]);

            return $ticket;
        });
        if (! $ticket) {
            return;
        }
        $streams = [];
        $sent = false;
        try {
            $actor = User::find($ticket->requested_by);
            $access = app(AccessService::class);
            if (! $actor || ! $access->write($actor) || ! $access->branches($actor)->whereKey($ticket->observation->device->branch_id)->exists()) {
                throw new TicketProviderException('The requester no longer has access. Ask an administrator to review this request.');
            }
            $payload = $ticket->payload ?? [];
            $payload['requester_email'] ??= $actor->email;
            $payload['attachments'] = [];
            foreach ($payload['attachment_ids'] ?? [] as $id) {
                $file = Attachment::where('observation_id', $ticket->observation_id)->find($id);
                if (! $file || $file->disk !== 'local' || ! preg_match('~\Aevidence/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)\z~D', $file->path)
                    || $file->size > 10 * 1024 * 1024) {
                    throw new TicketProviderException('Ticket evidence has changed or is unavailable. Review the daily record before retrying.');
                }
                $stream = Storage::disk('local')->readStream($file->path);
                if (! is_resource($stream)) {
                    throw new TicketProviderException('Ticket evidence is unavailable. Review the daily record before retrying.');
                }
                $streams[] = $stream;
                // Files are re-encoded JPEGs locally; use their generated names and actual MIME.
                $payload['attachments'][] = ['stream' => $stream, 'name' => basename($file->path), 'mime' => $file->mime];
            }
            unset($payload['attachment_ids']);
            $sent = true;
            $result = $provider->create($payload, $ticket->idempotency_key);
            if (empty($result['id']) || ! filter_var($result['url'] ?? null, FILTER_VALIDATE_URL) || parse_url($result['url'], PHP_URL_SCHEME) !== 'https') {
                throw new TicketProviderException('Creation could not be confirmed. Check Support Hub before any new attempt.', true);
            }
            $ticket->update(['status' => 'created', 'external_id' => $result['id'], 'external_url' => $result['url'], 'processing_at' => null, 'error' => $result['warning'] ?? null]);
        } catch (Throwable $error) {
            $uncertain = $error instanceof TicketProviderException ? $error->uncertain : $sent;
            $message = $error instanceof TicketProviderException ? $error->getMessage()
                : ($uncertain ? 'Creation could not be confirmed. A ticket may already exist. Check Support Hub before any new attempt.' : 'The request could not be prepared. Ask an administrator to review it.');
            $ticket->update(['status' => $uncertain ? 'unconfirmed' : 'failed', 'processing_at' => null, 'error' => $message]);
            // Do not chain raw HTTP exceptions: they can contain the bearer token or response data.
            throw new TicketProviderException($message, $uncertain);
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        TicketRequest::whereKey($this->ticketId)->whereNull('external_url')->whereIn('status', ['processing', 'queued'])
            ->update(['status' => 'unconfirmed', 'processing_at' => null, 'error' => 'Creation was interrupted and may have completed in Support Hub. Check there before any new attempt.']);
    }
}
