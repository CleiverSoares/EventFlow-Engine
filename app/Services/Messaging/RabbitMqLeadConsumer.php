<?php

namespace App\Services\Messaging;

use App\Contracts\MessagePublisher;
use App\Enums\MessageHandleResult;
use App\Observability\InMemoryMetricsRegistry;
use App\Observability\TraceContext;
use App\Observability\Tracing;
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
        private Tracing $tracing,
        private InMemoryMetricsRegistry $metrics,
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
        $headers = $this->messageHeaders($message);

        $parent = TraceContext::fromMessageHeaders($headers)
            ?? TraceContext::fromTraceparent(
                isset($body['payload']['traceparent']) ? (string) $body['payload']['traceparent'] : null,
            );

        return $this->tracing->run(
            'lead.consume',
            $parent,
            function (TraceContext $span) use ($body, $attempts): MessageHandleResult {
                /** @var array<string, mixed> $payload */
                $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
                $this->tracing->withTenant(isset($payload['tenant_id']) ? (string) $payload['tenant_id'] : null);

                $result = $this->handler->handle($body, $attempts);

                if ($result === MessageHandleResult::Retry) {
                    $this->publisher->publishRetry($body, array_merge([
                        'x-attempts' => $attempts + 1,
                        'outbox_id' => (string) ($body['outbox_id'] ?? ''),
                    ], $span->toMessageHeaders()));
                }

                if ($result === MessageHandleResult::Dlq) {
                    $this->publisher->publishDlq($body, array_merge([
                        'x-attempts' => $attempts + 1,
                        'outbox_id' => (string) ($body['outbox_id'] ?? ''),
                    ], $span->toMessageHeaders()));
                }

                $this->metrics->incrementCounter('eventflow_leads_consumed_total', [
                    'result' => $result->value,
                ]);

                return $result;
            },
            [
                'outbox_id' => (string) ($body['outbox_id'] ?? ''),
                'attempts' => $attempts,
            ],
        );
    }

    public function __destruct()
    {
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function messageHeaders(AMQPMessage $message): array
    {
        $props = $message->get_properties();
        $headers = $props['application_headers'] ?? null;

        if ($headers instanceof AMQPTable) {
            return $headers->getNativeData();
        }

        return [];
    }

    private function attemptCount(AMQPMessage $message): int
    {
        return (int) ($this->messageHeaders($message)['x-attempts'] ?? 0);
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
