<?php

namespace App\Services\Messaging;

use App\Contracts\MessagePublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMqMessagePublisher implements MessagePublisher
{
    private ?AMQPStreamConnection $connection = null;

    public function declareTopology(): void
    {
        $channel = $this->connection()->channel();

        $main = (string) config('eventflow.rabbitmq.queue');
        $retry = (string) config('eventflow.rabbitmq.retry_queue');
        $dlq = (string) config('eventflow.rabbitmq.dlq');

        $channel->exchange_declare('eventflow.leads', 'topic', false, true, false);

        $channel->queue_declare($dlq, false, true, false, false);
        $channel->queue_bind($dlq, 'eventflow.leads', 'leads.dlq');

        $channel->queue_declare($retry, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => 'eventflow.leads',
            'x-dead-letter-routing-key' => 'leads.incoming',
            'x-message-ttl' => 30000,
        ]));
        $channel->queue_bind($retry, 'eventflow.leads', 'leads.retry');

        $channel->queue_declare($main, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => 'eventflow.leads',
            'x-dead-letter-routing-key' => 'leads.dlq',
        ]));
        $channel->queue_bind($main, 'eventflow.leads', 'leads.incoming');

        $channel->close();
    }

    public function publish(array $body, array $headers = []): void
    {
        $this->declareTopology();

        $channel = $this->connection()->channel();
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $message = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'application_headers' => new AMQPTable($headers),
        ]);

        $channel->basic_publish($message, 'eventflow.leads', 'leads.incoming');
        $channel->close();
    }

    public function __destruct()
    {
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }
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
