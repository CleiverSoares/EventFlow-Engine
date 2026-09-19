<?php

namespace App\Exceptions;

use RuntimeException;

class CircuitOpenException extends RuntimeException
{
    public function __construct(string $circuitName)
    {
        parent::__construct("Circuit breaker [{$circuitName}] is open.");
    }
}
