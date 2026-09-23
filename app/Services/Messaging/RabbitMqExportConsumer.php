<?php

namespace App\Services\Messaging;

use App\Contracts\ExportWakePublisher;
use App\Observability\InMemoryMetricsRegistry;
use App\Repositories\ExportRepository;
use App\Services\ExportService;
use Illuminate\Support\Facades\Cache;
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
     * Non-blocking: get one wake (if any), drain fair claims, ack, drop redundant wakes.
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

        // One handle is enough — ack-drop the rest so delayed-wake storms cannot form.
        $dropped = 0;
        while ($dropped < 200) {
            $extra = $channel->basic_get($queue, true);
            if ($extra === null) {
                break;
            }
            $dropped++;
        }

        $channel->close();

        return [
            'wakes' => 1 + $dropped,
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
            $delayed = $this->publishCoalescedWake('delayed');
            $this->metrics->incrementCounter('eventflow_exports_wake_total', [
                'result' => $delayed ? 'delayed' : 'delayed_coalesced',
            ]);
        } elseif ($processed > 0) {
            $this->metrics->incrementCounter('eventflow_exports_wake_total', ['result' => 'drained']);
            if ($this->exportRepository->hasPending()) {
                $this->publishCoalescedWake('continue');
            }
        } else {
            $this->metrics->incrementCounter('eventflow_exports_wake_total', ['result' => 'idle']);
        }

        return ['processed' => $processed, 'delayed' => $delayed];
    }

    /**
     * Prevent 6 workers × N wakes from republishing the same signal.
     */
    private function publishCoalescedWake(string $kind): bool
    {
        $ttlMs = $kind === 'delayed'
            ? max(500, (int) config('eventflow.exports.rabbitmq.retry_ttl_ms', 3000))
            : max(50, (int) config('eventflow.exports.wake_coalesce_ms', 250));

        $published = Cache::add(
            'eventflow:exports:wake:'.$kind,
            1,
            now()->addMilliseconds($ttlMs),
        );

        if (! $published) {
            return false;
        }

        if ($kind === 'delayed') {
            $this->wake->publishDelayedWake(['type' => 'export.wake.delayed']);
        } else {
            $this->wake->publishWake(['type' => 'export.wake.'.$kind]);
        }

        return true;
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
