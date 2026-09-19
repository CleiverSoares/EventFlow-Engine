<?php

namespace Tests\Unit;

use App\Enums\MessageHandleResult;
use App\Services\LeadMessageHandler;
use App\Services\LeadProcessingPipeline;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LeadMessageHandlerTest extends TestCase
{
    public function test_happy_path_acks_after_pipeline_process(): void
    {
        $pipeline = Mockery::mock(LeadProcessingPipeline::class);
        $pipeline->shouldReceive('process')
            ->once()
            ->withArgs(function (string $key, array $payload): bool {
                return $key === 'outbox-1'
                    && ($payload['tenant_id'] ?? null) === 'tenant-1'
                    && ($payload['cnpj'] ?? null) === '12345678000199';
            })
            ->andReturn(['status' => 'processed', 'enrichment' => ['razao_social' => 'ACME']]);

        $handler = new LeadMessageHandler($pipeline);

        $result = $handler->handle([
            'outbox_id' => 'outbox-1',
            'aggregate_type' => 'lead.incoming',
            'payload' => [
                'tenant_id' => 'tenant-1',
                'cnpj' => '12345678000199',
            ],
        ]);

        $this->assertSame(MessageHandleResult::Ack, $result);
    }

    public function test_lock_busy_returns_retry_without_failure_path(): void
    {
        $pipeline = Mockery::mock(LeadProcessingPipeline::class);
        $pipeline->shouldReceive('process')
            ->once()
            ->andReturn(['status' => 'busy']);

        $handler = new LeadMessageHandler($pipeline);

        $result = $handler->handle([
            'outbox_id' => 'outbox-busy',
            'payload' => ['tenant_id' => 't', 'cnpj' => '12345678000199'],
        ]);

        $this->assertSame(MessageHandleResult::Retry, $result);
    }

    public function test_processing_failure_retries_then_dlq_at_max_attempts(): void
    {
        config(['eventflow.outbox.max_attempts' => 3]);

        $pipeline = Mockery::mock(LeadProcessingPipeline::class);
        $pipeline->shouldReceive('process')
            ->twice()
            ->andThrow(new RuntimeException('boom'));

        $handler = new LeadMessageHandler($pipeline);

        $this->assertSame(MessageHandleResult::Retry, $handler->handle(['outbox_id' => 'x', 'payload' => []], 0));
        $this->assertSame(MessageHandleResult::Dlq, $handler->handle(['outbox_id' => 'x', 'payload' => []], 2));
    }
}
