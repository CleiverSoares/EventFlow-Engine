<?php

namespace App\Services;

use App\Contracts\MessagePublisher;
use App\Repositories\OutboxEventRepository;
use Throwable;

class OutboxRelayService
{
    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private MessagePublisher $publisher,
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
                $this->publisher->publish([
                    'outbox_id' => $event->id,
                    'aggregate_type' => $event->aggregate_type,
                    'payload' => $event->payload,
                ], [
                    'outbox_id' => $event->id,
                    'aggregate_type' => $event->aggregate_type,
                ]);

                $this->outboxEvents->markProcessed($event);
                $processed++;
            } catch (Throwable) {
                $this->outboxEvents->registerFailure($event, $maxAttempts);
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
