<?php

namespace App\Contracts;

interface MessagePublisher
{
    /**
     * Ensure main queue, retry queue, and DLQ topology exist.
     */
    public function declareTopology(): void;

    /**
     * Publish a JSON-serializable message body to the main queue.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, scalar>  $headers
     */
    public function publish(array $body, array $headers = []): void;
}
