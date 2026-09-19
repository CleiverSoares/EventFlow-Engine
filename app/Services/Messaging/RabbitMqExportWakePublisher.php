<?php

namespace App\Services\Messaging;

use App\Contracts\ExportWakePublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMqExportWakePublisher implements ExportWakePublisher
{
    private const EXCHANGE = 'eventflow.exports';

    private const ROUTING_WAKE = 'exports.requested';

    private const ROUTING_RETRY = 'exports.retry';

    private ?AMQPStreamConnection $connection = null;

    public function declareTopology(): void
    {
        $channel = $this->connection()->channel();

        $main = (string) config('eventflow.exports.rabbitmq.queue', 'exports.requested');
        $retry = (string) config('eventflow.exports.rabbitmq.retry_queue', 'exports.requested.retry');
        $ttl = (int) config('eventflow.exports.rabbitmq.retry_ttl_ms', 3000);

        $channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);

        $channel->queue_declare($retry, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => self::EXCHANGE,
            'x-dead-letter-routing-key' => self::ROUTING_WAKE,
            'x-message-ttl' => max(500, $ttl),
        ]));
        $channel->queue_bind($retry, self::EXCHANGE, self::ROUTING_RETRY);

        $channel->queue_declare($main, false, true, false, false);
        $channel->queue_bind($main, self::EXCHANGE, self::ROUTING_WAKE);

        $channel->close();
    }

    public function publishWake(array $body = []): void
    {
        $this->publish(self::ROUTING_WAKE, $body === [] ? ['type' => 'export.wake'] : $body);
    }

    public function publishDelayedWake(array $body = []): void
    {
        $this->publish(self::ROUTING_RETRY, $body === [] ? ['type' => 'export.wake.delayed'] : $body);
    }

    public function __destruct()
    {
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function publish(string $routingKey, array $body): void
    {
        $this->declareTopology();

        $channel = $this->connection()->channel();
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $message = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $channel->basic_publish($message, self::EXCHANGE, $routingKey);
        $channel->close();
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
