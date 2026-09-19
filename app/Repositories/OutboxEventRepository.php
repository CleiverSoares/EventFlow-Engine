<?php

namespace App\Repositories;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OutboxEventRepository
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPending(
        string $tenantId,
        string $aggregateType,
        array $payload,
        ?string $idempotencyKey = null,
    ): OutboxEvent {
        return OutboxEvent::query()->create([
            'tenant_id' => $tenantId,
            'aggregate_type' => $aggregateType,
            'payload' => $payload,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function findByTenantAndIdempotencyKey(string $tenantId, string $idempotencyKey): ?OutboxEvent
    {
        return OutboxEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function findForTenant(string $tenantId, string $id): ?OutboxEvent
    {
        return OutboxEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, OutboxEvent>
     */
    public function paginateForTenant(
        string $tenantId,
        ?OutboxStatus $status = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = OutboxEvent::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function countPending(): int
    {
        return OutboxEvent::query()
            ->where('status', OutboxStatus::Pending)
            ->count();
    }

    public function countPendingForTenant(string $tenantId): int
    {
        return OutboxEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('status', OutboxStatus::Pending)
            ->count();
    }

    public function deleteProcessedOlderThan(Carbon $cutoff): int
    {
        return OutboxEvent::query()
            ->where('status', OutboxStatus::Processed)
            ->where('updated_at', '<', $cutoff)
            ->delete();
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
