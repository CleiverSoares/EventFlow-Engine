<?php

namespace App\Repositories;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OutboxEventRepository
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPending(string $aggregateType, array $payload): OutboxEvent
    {
        return OutboxEvent::query()->create([
            'aggregate_type' => $aggregateType,
            'payload' => $payload,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
        ]);
    }

    /**
     * @return Collection<int, OutboxEvent>
     */
    public function findPending(int $limit = 100): Collection
    {
        return OutboxEvent::query()
            ->where('status', OutboxStatus::Pending)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Claim PENDING rows for relay (PENDING → PROCESSING) with row locks when supported.
     *
     * @return Collection<int, OutboxEvent>
     */
    public function claimPending(int $limit = 100): Collection
    {
        return DB::transaction(function () use ($limit): Collection {
            $query = OutboxEvent::query()
                ->where('status', OutboxStatus::Pending)
                ->orderBy('created_at')
                ->limit($limit);

            if (DB::getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            /** @var Collection<int, OutboxEvent> $events */
            $events = $query->get();

            foreach ($events as $event) {
                $event->forceFill(['status' => OutboxStatus::Processing])->save();
            }

            return $events;
        });
    }

    public function markProcessed(OutboxEvent $event): OutboxEvent
    {
        if ($event->status !== OutboxStatus::Processing) {
            throw new InvalidArgumentException('Only PROCESSING outbox events can be marked PROCESSED.');
        }

        $event->forceFill(['status' => OutboxStatus::Processed])->save();

        return $event->refresh();
    }

    public function registerFailure(OutboxEvent $event, int $maxAttempts): OutboxEvent
    {
        if ($event->status === OutboxStatus::Processed) {
            throw new InvalidArgumentException('PROCESSED outbox events cannot be failed again.');
        }

        $attempts = $event->attempts + 1;
        $status = $attempts >= $maxAttempts
            ? OutboxStatus::Failed
            : OutboxStatus::Pending;

        $event->forceFill([
            'attempts' => $attempts,
            'status' => $status,
        ])->save();

        return $event->refresh();
    }
}
