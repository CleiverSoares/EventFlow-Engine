<?php

namespace App\Services\Messaging;

use App\Contracts\ExportWakePublisher;
use App\Observability\InMemoryMetricsRegistry;
use App\Repositories\ExportRepository;
use App\Services\ExportService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consume export wake signals, then fair-claim in Postgres (message is not "process this id").
 */
class RabbitMqExportConsumer
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct(
        private ExportService $exports,
        private ExportRepository $exportRepository,
        private ExportWakePublisher $wake,
        private InMemoryMetricsRegistry $metrics,
    ) {}

    /**
     * Non-blocking: get one wake (if any), drain fair claims, ack.
     *
     * @return array{wakes: int, processed: int, delayed: int}
     */
    public function pullAndHandle(): array
    {
        $this->wake->declareTopology();

        $channel = $this->connection()->channel();
        $queue = (string) config('eventflow.exports.rabbitmq.queue', 'exports.requested');

        /** @var AMQPMessage|null $message */
        $message = $channel->basic_get($queue, false);
        if ($message === null) {
            $channel->close();

            return ['wakes' => 0, 'processed' => 0, 'delayed' => 0];
        }

        $result = $this->handleWake();
        $channel->basic_ack($message->getDeliveryTag());
        $channel->close();

        return [
            'wakes' => 1,
            'processed' => $result['processed'],
            'delayed' => $result['delayed'] ? 1 : 0,
        ];
    }

    /**
     * Drain fair claims until caps block; schedule delayed wake if work remains.
     *
     * @return array{processed: int, delayed: bool}
     */
    public function handleWake(): array
    {
        $processed = 0;

        while (true) {
            $export = $this->exports->processNextFair();
            if ($export === null) {
                break;
            }
            $processed++;
        }

        $delayed = false;
        if ($processed === 0 && $this->exportRepository->hasPending()) {
            $this->wake->publishDelayedWake(['type' => 'export.wake.delayed']);
            $delayed = true;
            $this->metrics->incrementCounter('eventflow_exports_wake_total', ['result' => 'delayed']);
        } elseif ($processed > 0) {
            $this->metrics->incrementCounter('eventflow_exports_wake_total', ['result' => 'drained']);
            if ($this->exportRepository->hasPending()) {
                $this->wake->publishWake(['type' => 'export.wake.continue']);
            }
        } else {
            $this->metrics->incrementCounter('eventflow_exports_wake_total', ['result' => 'idle']);
        }

        return ['processed' => $processed, 'delayed' => $delayed];
    }

    private function connection(): AMQPStreamConnection
    {
        if ($this->connection?->isConnected()) {
            return $this->connection;
        }

        $this->connection = new AMQPStreamConnection(
            (string) config('eventflow.rabbitmq.host'),
            (int) config('eventflow.rabbitmq.port'),
            (string) config('eventflow.rabbitmq.user'),
            (string) config('eventflow.rabbitmq.password'),
            (string) config('eventflow.rabbitmq.vhost'),
        );

        return $this->connection;
    }

    public function __destruct()
    {
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }
    }
}
