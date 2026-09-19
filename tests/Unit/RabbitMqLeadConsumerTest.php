<?php

namespace Tests\Unit;

use App\Contracts\MessagePublisher;
use App\Enums\MessageHandleResult;
use App\Services\LeadMessageHandler;
use App\Services\Messaging\RabbitMqLeadConsumer;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

class RabbitMqLeadConsumerTest extends TestCase
{
    public function test_busy_message_is_published_to_retry_queue(): void
    {
        $handler = Mockery::mock(LeadMessageHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->withArgs(fn (array $body, int $attempts): bool => ($body['outbox_id'] ?? null) === 'ob-1' && $attempts === 1)
            ->andReturn(MessageHandleResult::Retry);

        $publisher = Mockery::mock(MessagePublisher::class);
        $publisher->shouldReceive('publishRetry')
            ->once()
            ->withArgs(function (array $body, array $headers): bool {
                return ($body['outbox_id'] ?? null) === 'ob-1'
                    && ($headers['x-attempts'] ?? null) === 2;
            });
        $publisher->shouldNotReceive('publishDlq');

        $message = new AMQPMessage(json_encode([
            'outbox_id' => 'ob-1',
            'payload' => ['cnpj' => '12345678000199'],
        ], JSON_THROW_ON_ERROR), [
            'application_headers' => new AMQPTable(['x-attempts' => 1]),
        ]);

        $consumer = new RabbitMqLeadConsumer($handler, $publisher);
        $result = $consumer->handleMessage($message);

        $this->assertSame(MessageHandleResult::Retry, $result);
    }

    public function test_poison_message_is_published_to_dlq(): void
    {
        $handler = Mockery::mock(LeadMessageHandler::class);
        $handler->shouldReceive('handle')->once()->andReturn(MessageHandleResult::Dlq);

        $publisher = Mockery::mock(MessagePublisher::class);
        $publisher->shouldReceive('publishDlq')->once();
        $publisher->shouldNotReceive('publishRetry');

        $message = new AMQPMessage(json_encode([
            'outbox_id' => 'ob-poison',
            'payload' => [],
        ], JSON_THROW_ON_ERROR));

        $consumer = new RabbitMqLeadConsumer($handler, $publisher);

        $this->assertSame(MessageHandleResult::Dlq, $consumer->handleMessage($message));
    }
}
