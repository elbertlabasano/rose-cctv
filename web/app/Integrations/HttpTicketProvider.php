<?php

namespace App\Integrations;

use App\Contracts\TicketProvider;
use Illuminate\Support\Facades\Http;

class HttpTicketProvider implements TicketProvider
{
    public function create(array $payload, string $idempotencyKey): array
    {
        $endpoint = config('tickets.endpoint');
        if (! $endpoint || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('An HTTPS ticket endpoint must be configured.');
        }
        $response = Http::acceptJson()->withToken((string) config('tickets.token'))->withHeaders(['Idempotency-Key' => $idempotencyKey])->connectTimeout(10)->timeout(45)->post($endpoint, $payload);
        if (! $response->successful()) {
            throw new \RuntimeException('Ticket provider could not confirm creation.');
        }
        $result = $response->json();
        if (! is_array($result) || ! is_scalar($result['id'] ?? null) || ! trim((string) $result['id']) || ! filter_var($result['url'] ?? null, FILTER_VALIDATE_URL) || parse_url($result['url'], PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('Ticket provider returned an invalid confirmation.');
        }

        return ['id' => (string) $result['id'], 'url' => $result['url']];
    }
}
