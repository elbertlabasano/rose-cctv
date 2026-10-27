<?php

namespace App\Exceptions;

use RuntimeException;

class BackupException extends RuntimeException
{
    public function __construct(string $message, public readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }
}
