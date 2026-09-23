<?php

namespace App\Contracts;

interface ExportWakePublisher
{
    /**
     * Ensure exports exchange + wake/retry queues exist.
     */
    public function declareTopology(): void;

    /**
     * Wake a fair export worker (signal only — claim stays in Postgres).
     *
     * @param  array<string, mixed>  $body
     */
    public function publishWake(array $body = []): void;

    /**
     * Delayed wake when tenants are at plan cap (retry TTL → main queue).
     *
     * @param  array<string, mixed>  $body
     */
    public function publishDelayedWake(array $body = []): void;
}
