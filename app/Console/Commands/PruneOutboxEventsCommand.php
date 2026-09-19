<?php

namespace App\Console\Commands;

use App\Services\OutboxRetentionService;
use Illuminate\Console\Command;

class PruneOutboxEventsCommand extends Command
{
    protected $signature = 'eventflow:prune-outbox {--days= : Retention days for PROCESSED rows}';

    protected $description = 'Delete PROCESSED outbox events older than the retention window';

    public function handle(OutboxRetentionService $retention): int
    {
        $days = (int) ($this->option('days') ?: config('eventflow.outbox.retention_days', 7));
        $deleted = $retention->pruneProcessed($days);

        $this->info("Pruned {$deleted} PROCESSED outbox event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
