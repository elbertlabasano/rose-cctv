<?php

namespace App\Exceptions;

use RuntimeException;

class TicketProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false)
    {
        parent::__construct($message);
    }
}
