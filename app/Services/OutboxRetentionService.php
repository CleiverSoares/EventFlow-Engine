<?php

namespace App\Services;

use App\Repositories\OutboxEventRepository;
use Illuminate\Support\Carbon;

class OutboxRetentionService
{
    public function __construct(private OutboxEventRepository $outboxEvents) {}

    public function pruneProcessed(int $retentionDays): int
    {
        $days = max(1, $retentionDays);
        $cutoff = Carbon::now()->subDays($days);

        return $this->outboxEvents->deleteProcessedOlderThan($cutoff);
    }
}
