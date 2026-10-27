<?php

namespace App\Integrations;

use App\Contracts\TicketProvider;
use App\Exceptions\TicketProviderException;
use App\Services\TicketIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpTicketProvider implements TicketProvider
{
    public function __construct(private TicketIntegration $integration) {}

    public function create(array $payload, string $idempotencyKey): array
    {
        if (! $this->integration->canCreate()) {
            throw new TicketProviderException('Ticket integration setup is incomplete. Ask an administrator to check routing and credentials.');
        }
        $request = $this->request();
        $body = [
            'name' => $payload['requested_by'] ?? '', 'email' => $payload['requester_email'] ?? '',
            'department_id' => (int) config('tickets.department_id'), 'category_id' => (int) config('tickets.category_id'),
            'assignee_id' => (int) config('tickets.assignee_id'),
            'subject' => $payload['title'] ?? '',
            'description' => implode("\n", [
                'Camera: '.($payload['camera_id'] ?? '').' ? '.($payload['camera_name'] ?? ''),
                'Branch: '.($payload['branch'] ?? ''), 'Record date: '.($payload['date'] ?? ''),
                'Observed status: '.($payload['status'] ?? 'Not checked'), '', $payload['notes'] ?? '',
                '', 'RoseWatch request: '.$idempotencyKey,
            ]),
            'priority' => config('tickets.priority'), 'ticket_type' => config('tickets.ticket_type'),
            'source_app' => 'RoseWatch', 'external_reference' => $idempotencyKey,
        ];
        $board = $payload['board_id'] ?? config('tickets.board_id');
        if ($board) {
            if (! in_array((int) $board, array_column(config('tickets.boards', []), 'id'), true)) {
                throw new TicketProviderException('Choose a configured CCTV ticket board.');
            }
            $body['board_id'] = (int) $board;
        }
        if (! trim($body['name']) || ! filter_var($body['email'], FILTER_VALIDATE_EMAIL) || ! trim($body['subject'])) {
            throw new TicketProviderException('Requester name, email and ticket title are required before sending.');
        }
        $files = $payload['attachments'] ?? [];
        if (count($files) > 10) {
            throw new TicketProviderException('Support Hub accepts up to ten attachments.');
        }
        foreach ($files as $file) {
            if (! is_resource($file['stream'] ?? null)) {
                throw new TicketProviderException('Ticket evidence is unavailable. Check the daily record before retrying.');
            }
            $request->attach('attachments[]', $file['stream'], $file['name'], ['Content-Type' => $file['mime']]);
        }
        try {
            // One POST only: external_reference is a correlation key, not an idempotency guarantee.
            $response = $request->post(config('tickets.endpoint'), $body);
        } catch (Throwable) {
            throw new TicketProviderException('Creation could not be confirmed. A ticket may already exist. Check Support Hub before any new attempt.', true);
        }
        if (in_array($response->status(), [400, 401, 403, 404, 413, 422, 429], true)) {
            throw new TicketProviderException('Support Hub rejected the request (HTTP '.$response->status().'). Ask an administrator to check routing, account access and attachments before retrying.');
        }
        $data = $response->json('data');
        if ($response->status() !== 201 || $response->json('success') !== true || ! is_array($data)
            || (! is_string($data['ticket_id'] ?? null) && ! is_int($data['ticket_id'] ?? null))
            || trim((string) $data['ticket_id']) === '' || strlen((string) $data['ticket_id']) > 255
            || ! $this->validTicketUrl($data['url'] ?? null)
            || (isset($data['external_reference']) && $data['external_reference'] !== $idempotencyKey)) {
            throw new TicketProviderException('Creation could not be confirmed. A ticket may already exist. Check Support Hub before any new attempt.', true);
        }

        return ['id' => (string) $data['ticket_id'], 'url' => $data['url'],
            'warning' => ($data['assignee_id'] ?? null) == config('tickets.assignee_id') ? null
                : 'Ticket created; the requested assignment was not confirmed. Check assignment in Support Hub.'];
    }

    public function tickets(int $page = 1): array
    {
        if (! $this->integration->canView()) {
            throw new TicketProviderException('Ticket viewing is not configured. Ask an administrator to check the API token and assigned-user ID.');
        }
        try {
            $response = $this->request()->timeout(15)->get(config('tickets.endpoint'), [
                'assigned_id' => (int) config('tickets.assignee_id'), 'page' => $page, 'per_page' => 50,
            ]);
        } catch (Throwable) {
            throw new TicketProviderException('Live tickets could not be loaded. Please try again later.');
        }
        $data = $response->json('data');
        $meta = $response->json('meta');
        if (! $response->successful() || $response->json('success') !== true || ! is_array($data) || ! array_is_list($data)
            || count($data) > 100 || ! is_array($meta) || (int) ($meta['current_page'] ?? 0) !== $page
            || ! is_numeric($meta['last_page'] ?? null) || (int) $meta['last_page'] < 1) {
            throw new TicketProviderException('Live tickets could not be loaded. Ask an administrator to check API access.');
        }
        $items = [];
        foreach ($data as $ticket) {
            if (! is_array($ticket) || ! is_scalar($ticket['ticket_id'] ?? null) || ! $this->validTicketUrl($ticket['url'] ?? null)) {
                throw new TicketProviderException('Support Hub returned an invalid ticket list. Please try again later.');
            }
            $text = static fn ($value, $limit = 500) => is_string($value) ? mb_substr($value, 0, $limit) : '';
            $items[] = [
                'id' => (string) $ticket['ticket_id'], 'url' => $ticket['url'],
                'subject' => $text($ticket['subject'] ?? ''), 'description' => $text($ticket['description'] ?? '', 20000),
                'status' => $text($ticket['status'] ?? '', 60), 'priority' => $text($ticket['priority'] ?? '', 30),
                'board' => $text($ticket['board']['name'] ?? ''),
                'assignees' => array_values(array_map(fn ($item) => $text($item['name'] ?? ''), array_slice(is_array($ticket['assignees'] ?? null) ? $ticket['assignees'] : [], 0, 30))),
                'due_date' => $text($ticket['due_date'] ?? '', 40), 'updated_at' => $text($ticket['updated_at'] ?? '', 40),
            ];
        }

        // Ignore API pagination URLs: subsequent GETs always use the configured endpoint.
        return ['data' => $items, 'page' => $page, 'has_more' => $page < (int) $meta['last_page']];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->withToken(config('tickets.token'))->withoutRedirecting()->connectTimeout(5)->timeout(45);
    }

    private function validTicketUrl(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $endpoint = parse_url(config('tickets.endpoint'));

        return ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === ($endpoint['host'] ?? '')
            && ($parts['port'] ?? 443) === ($endpoint['port'] ?? 443) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('~\A/app/ticket/[A-Za-z0-9_-]+\z~D', $parts['path'] ?? '') === 1;
    }
}
