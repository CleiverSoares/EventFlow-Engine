<?php

namespace App\Services;

use App\Contracts\MessagePublisher;
use App\Observability\InMemoryMetricsRegistry;
use App\Observability\TraceContext;
use App\Observability\Tracing;
use App\Repositories\OutboxEventRepository;
use Throwable;

class OutboxRelayService
{
    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private MessagePublisher $publisher,
        private Tracing $tracing,
        private InMemoryMetricsRegistry $metrics,
    ) {}

    /**
     * @return array{claimed: int, processed: int, failed: int}
     */
    public function relayBatch(?int $limit = null): array
    {
        $limit ??= (int) config('eventflow.outbox.batch_size', 100);
        $maxAttempts = (int) config('eventflow.outbox.max_attempts', 5);

        $claimed = $this->outboxEvents->claimPending($limit);
        $processed = 0;
        $failed = 0;

        foreach ($claimed as $event) {
            try {
                /** @var array<string, mixed> $payload */
                $payload = is_array($event->payload) ? $event->payload : [];
                $parent = TraceContext::fromTraceparent(
                    isset($payload['traceparent']) ? (string) $payload['traceparent'] : null,
                );

                $this->tracing->run(
                    'outbox.relay',
                    $parent,
                    function (TraceContext $span) use ($event, $payload): void {
                        $this->publisher->publish([
                            'outbox_id' => $event->id,
                            'aggregate_type' => $event->aggregate_type,
                            'payload' => $payload,
                        ], array_merge([
                            'outbox_id' => $event->id,
                            'aggregate_type' => $event->aggregate_type,
                        ], $span->toMessageHeaders()));
                    },
                    [
                        'outbox_id' => $event->id,
                        'aggregate_type' => $event->aggregate_type,
                        'tenant_id' => (string) ($payload['tenant_id'] ?? ''),
                    ],
                );

                $this->outboxEvents->markProcessed($event);
                $this->metrics->incrementCounter('eventflow_outbox_relay_total', ['result' => 'processed']);
                $processed++;
            } catch (Throwable) {
                $this->outboxEvents->registerFailure($event, $maxAttempts);
                $this->metrics->incrementCounter('eventflow_outbox_relay_total', ['result' => 'failed']);
                $failed++;
            }
        }

        return [
            'claimed' => $claimed->count(),
            'processed' => $processed,
            'failed' => $failed,
        ];
    }
}
