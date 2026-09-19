<?php

namespace App\Services;

use App\Enums\MessageHandleResult;
use Throwable;

/**
 * Orchestrates Phase 2 queue messages: lock → enrich/geo → dispatch/audit.
 */
class LeadMessageHandler
{
    public function __construct(
        private LeadProcessingPipeline $pipeline,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public function handle(array $body, int $attempts = 0): MessageHandleResult
    {
        $rawPayload = $body['payload'] ?? $body;
        /** @var array<string, mixed> $payload */
        $payload = is_array($rawPayload) ? $rawPayload : [];

        $idempotencyKey = (string) ($body['outbox_id'] ?? $payload['outbox_id'] ?? $payload['idempotency_key'] ?? '');

        if ($idempotencyKey === '') {
            $idempotencyKey = 'lead-'.md5(json_encode($payload) ?: uniqid('', true));
        }

        $maxAttempts = (int) config('eventflow.outbox.max_attempts', 5);

        try {
            $result = $this->pipeline->process($idempotencyKey, $payload);

            if (($result['status'] ?? null) === 'busy') {
                return MessageHandleResult::Retry;
            }

            return MessageHandleResult::Ack;
        } catch (Throwable) {
            return ($attempts + 1) >= $maxAttempts
                ? MessageHandleResult::Dlq
                : MessageHandleResult::Retry;
        }
    }
}
