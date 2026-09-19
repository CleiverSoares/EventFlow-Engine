<?php

namespace App\Console\Commands;

use App\Services\OutboxRelayService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eventflow:relay-outbox {--limit= : Max PENDING rows to claim}')]
#[Description('Publish PENDING outbox events to RabbitMQ')]
class RelayOutboxEventsCommand extends Command
{
    public function handle(OutboxRelayService $relay): int
    {
        $limit = $this->option('limit');
        $result = $relay->relayBatch($limit !== null ? (int) $limit : null);

        $this->info(sprintf(
            'Relay finished: claimed=%d processed=%d failed=%d',
            $result['claimed'],
            $result['processed'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
