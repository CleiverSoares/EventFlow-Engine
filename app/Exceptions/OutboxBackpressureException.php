<?php

namespace App\Exceptions;

use RuntimeException;

class OutboxBackpressureException extends RuntimeException
{
    public function __construct(string $message = 'Outbox backlog is too high. Retry later.')
    {
        parent::__construct($message);
    }
}
