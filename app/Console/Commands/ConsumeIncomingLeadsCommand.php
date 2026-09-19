<?php

namespace App\Console\Commands;

use App\Services\Messaging\RabbitMqLeadConsumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eventflow:consume-leads {--max= : Stop after N messages (omit for continuous)}')]
#[Description('Consume RabbitMQ lead messages: lock → enrich → geo → dispatch → audit')]
class ConsumeIncomingLeadsCommand extends Command
{
    public function handle(RabbitMqLeadConsumer $consumer): int
    {
        $max = $this->option('max');

        $this->info('Consuming leads.incoming (Ctrl+C to stop)...');

        $result = $consumer->consume($max !== null ? (int) $max : null);

        $this->info(sprintf(
            'Consume finished: acked=%d retried=%d dlq=%d',
            $result['acked'],
            $result['retried'],
            $result['dlq'],
        ));

        return self::SUCCESS;
    }
}
