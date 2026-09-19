<?php

namespace App\Services\Messaging;

use App\Contracts\MessagePublisher;
use App\Enums\MessageHandleResult;
use App\Services\LeadMessageHandler;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMqLeadConsumer
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct(
        private LeadMessageHandler $handler,
        private MessagePublisher $publisher,
    ) {}

    /**
     * Consume up to $maxMessages (null = until interrupted). Returns counts.
     *
     * @return array{acked: int, retried: int, dlq: int}
     */
    public function consume(?int $maxMessages = null): array
    {
        $this->publisher->declareTopology();

        $channel = $this->connection()->channel();
        $queue = (string) config('eventflow.rabbitmq.queue');
        $channel->basic_qos(0, 1, null);

        $acked = 0;
        $retried = 0;
        $dlq = 0;
        $processed = 0;
        $consumerTag = 'eventflow-leads-'.getmypid();

        $callback = function (AMQPMessage $message) use ($channel, &$acked, &$retried, &$dlq, &$processed, $maxMessages, $consumerTag): void {
            $result = $this->handleMessage($message);

            match ($result) {
                MessageHandleResult::Ack => $acked++,
                MessageHandleResult::Retry => $retried++,
                MessageHandleResult::Dlq => $dlq++,
            };

            $channel->basic_ack($message->getDeliveryTag());
            $processed++;

            if ($maxMessages !== null && $processed >= $maxMessages) {
                $channel->basic_cancel($consumerTag);
            }
        };

        $channel->basic_consume($queue, $consumerTag, false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            try {
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                if ($maxMessages !== null && $processed >= $maxMessages) {
                    break;
                }
            }

            if ($maxMessages !== null && $processed >= $maxMessages) {
                break;
            }
        }

        $channel->close();

        return [
            'acked' => $acked,
            'retried' => $retried,
            'dlq' => $dlq,
        ];
    }

    public function handleMessage(AMQPMessage $message): MessageHandleResult
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($message->getBody(), true) ?? [];
        $attempts = $this->attemptCount($message);

        $result = $this->handler->handle($body, $attempts);

        if ($result === MessageHandleResult::Retry) {
            $this->publisher->publishRetry($body, [
                'x-attempts' => $attempts + 1,
                'outbox_id' => (string) ($body['outbox_id'] ?? ''),
            ]);
        }

        if ($result === MessageHandleResult::Dlq) {
            $this->publisher->publishDlq($body, [
                'x-attempts' => $attempts + 1,
                'outbox_id' => (string) ($body['outbox_id'] ?? ''),
            ]);
        }

        return $result;
    }

    public function __destruct()
    {
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }
    }

    private function attemptCount(AMQPMessage $message): int
    {
        $props = $message->get_properties();
        $headers = $props['application_headers'] ?? null;

        if ($headers instanceof AMQPTable) {
            $native = $headers->getNativeData();

            return (int) ($native['x-attempts'] ?? 0);
        }

        return 0;
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
}
