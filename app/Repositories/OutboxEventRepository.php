<?php

namespace App\Repositories;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use Illuminate\Support\Collection;

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
}
