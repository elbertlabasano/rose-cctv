<?php

namespace App\Contracts;

interface TicketProvider
{
    /** Return ['id' => string, 'url' => https URL] after provider confirmation. */
    public function create(array $payload, string $idempotencyKey): array;
}
