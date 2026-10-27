<?php

namespace App\Contracts;

interface TicketProvider
{
    /** Return ['id' => string, 'url' => https URL] after provider confirmation. */
    public function create(array $payload, string $idempotencyKey): array;

    /** Read one provider page. No update, delete, comment or status-change operations. */
    public function tickets(int $page = 1): array;
}
