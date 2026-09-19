<?php

namespace Tests\Unit;

use App\Services\LeadEnrichmentService;
use App\Services\LeadProcessingPipeline;
use App\Services\Locking\LeadProcessingLock;
use Mockery;
use Tests\TestCase;

class LeadProcessingPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eventflow.redis.lock_prefix' => 'eventflow:lock:pipeline:',
            'eventflow.redis.lock_ttl_seconds' => 30,
        ]);
    }

    public function test_process_enriches_when_lock_acquired(): void
    {
        $enrichment = Mockery::mock(LeadEnrichmentService::class);
        $enrichment->shouldReceive('enrichByCnpj')
            ->once()
            ->with('12345678000199')
            ->andReturn(['cnpj' => '12345678000199', 'razao_social' => 'ACME']);

        $pipeline = new LeadProcessingPipeline(new LeadProcessingLock, $enrichment);

        $result = $pipeline->process('idem-1', ['cnpj' => '12345678000199']);

        $this->assertSame('processed', $result['status']);
        $this->assertSame('ACME', $result['enrichment']['razao_social']);
    }

    public function test_process_returns_busy_when_lock_held(): void
    {
        $holder = new LeadProcessingLock;
        $holder->acquire('idem-busy');

        $enrichment = Mockery::mock(LeadEnrichmentService::class);
        $enrichment->shouldNotReceive('enrichByCnpj');

        $pipeline = new LeadProcessingPipeline(new LeadProcessingLock, $enrichment);
        $result = $pipeline->process('idem-busy', ['cnpj' => '12345678000199']);

        $this->assertSame('busy', $result['status']);
        $this->assertArrayNotHasKey('enrichment', $result);

        $holder->release('idem-busy');
    }
}
