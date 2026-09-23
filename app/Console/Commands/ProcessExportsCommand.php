<?php

namespace App\Console\Commands;

use App\Services\ExportService;
use App\Services\Messaging\RabbitMqExportConsumer;
use Illuminate\Console\Command;
use Throwable;

class ProcessExportsCommand extends Command
{
    protected $signature = 'eventflow:process-exports
        {--once : Process a single fair export and exit}
        {--poll : Poll Postgres instead of consuming RabbitMQ wakes}
        {--sleep=1 : Seconds to sleep when idle (poll mode or Rabbit idle catch-up)}';

    protected $description = 'Fair CRM export worker (RabbitMQ wake + Postgres claim, or --poll)';

    public function handle(ExportService $exports, RabbitMqExportConsumer $consumer): int
    {
        if ($this->option('once')) {
            $export = $exports->processNextFair();
            if ($export === null) {
                $this->info('No export claimed.');

                return self::SUCCESS;
            }

            $this->info("Processed {$export->id} → {$export->status->value} rows=".($export->row_count ?? 0));

            return self::SUCCESS;
        }

        if ($this->option('poll')) {
            return $this->pollLoop($exports);
        }

        return $this->rabbitLoop($exports, $consumer);
    }

    private function pollLoop(ExportService $exports): int
    {
        $sleep = max(1, (int) $this->option('sleep'));
        $this->info('Polling exports with fairness (Ctrl+C to stop)...');

        while (true) {
            $export = $exports->processNextFair();
            if ($export === null) {
                sleep($sleep);

                continue;
            }

            $this->line(now()->toDateTimeString()." {$export->id} tenant={$export->tenant_id} {$export->status->value}");
        }
    }

    private function rabbitLoop(ExportService $exports, RabbitMqExportConsumer $consumer): int
    {
        $sleep = max(1, (int) $this->option('sleep'));
        $this->info('Consuming export wakes from RabbitMQ (fair claim in Postgres)...');

        while (true) {
            try {
                $stats = $consumer->pullAndHandle();
                if ($stats['wakes'] > 0) {
                    $this->line(
                        now()->toDateTimeString()
                        ." wake processed={$stats['processed']} delayed=".($stats['delayed'] ? 'yes' : 'no')
                    );

                    continue;
                }

                // Lost wake / empty queue: catch-up claim, then brief wait.
                $export = $exports->processNextFair();
                if ($export !== null) {
                    $this->line(now()->toDateTimeString()." catch-up {$export->id} {$export->status->value}");

                    continue;
                }

                sleep($sleep);
            } catch (Throwable $exception) {
                $this->error('Export consumer error: '.$exception->getMessage());
                sleep($sleep);
            }
        }
    }
}
