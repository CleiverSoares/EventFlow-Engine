<?php

namespace App\Contracts;

interface WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(array $payload): void;
}
