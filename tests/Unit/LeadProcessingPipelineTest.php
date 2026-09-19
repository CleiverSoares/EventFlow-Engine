<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Services\Geo\LeadGeoIndex;
use App\Services\LeadDispatchService;
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

        $geo = Mockery::mock(LeadGeoIndex::class);
        $geo->shouldReceive('maybeIndex')
            ->once()
            ->with('idem-1', Mockery::on(fn (array $payload): bool => ($payload['cnpj'] ?? null) === '12345678000199'))
            ->andReturn(false);

        $dispatch = Mockery::mock(LeadDispatchService::class);
        $dispatch->shouldNotReceive('dispatchAndAudit');

        $pipeline = new LeadProcessingPipeline(new LeadProcessingLock, $enrichment, $geo, $dispatch);

        $result = $pipeline->process('idem-1', ['cnpj' => '12345678000199']);

        $this->assertSame('processed', $result['status']);
        $this->assertSame('ACME', $result['enrichment']['razao_social']);
        $this->assertFalse($result['geo_indexed']);
    }

    public function test_process_indexes_geo_and_dispatches_when_tenant_present(): void
    {
        $enrichment = Mockery::mock(LeadEnrichmentService::class);
        $enrichment->shouldReceive('enrichByCnpj')->once()->andReturn(['cnpj' => '12345678000199']);

        $geo = Mockery::mock(LeadGeoIndex::class);
        $geo->shouldReceive('maybeIndex')
            ->once()
            ->with('idem-geo', Mockery::on(fn (array $payload): bool => isset($payload['lat'], $payload['lng'])))
            ->andReturn(true);

        $audit = new AuditLog(['tenant_id' => '11111111-1111-1111-1111-111111111111']);
        $audit->id = '33333333-3333-3333-3333-333333333333';

        $dispatch = Mockery::mock(LeadDispatchService::class);
        $dispatch->shouldReceive('dispatchAndAudit')
            ->once()
            ->with('11111111-1111-1111-1111-111111111111', Mockery::type('array'))
            ->andReturn($audit);

        $pipeline = new LeadProcessingPipeline(new LeadProcessingLock, $enrichment, $geo, $dispatch);

        $result = $pipeline->process('idem-geo', [
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'cnpj' => '12345678000199',
            'lat' => -23.55,
            'lng' => -46.63,
        ]);

        $this->assertSame('processed', $result['status']);
        $this->assertTrue($result['geo_indexed']);
        $this->assertSame($audit->id, $result['audit']->id);
    }

    public function test_process_returns_busy_when_lock_held(): void
    {
        $holder = new LeadProcessingLock;
        $holder->acquire('idem-busy');

        $enrichment = Mockery::mock(LeadEnrichmentService::class);
        $enrichment->shouldNotReceive('enrichByCnpj');

        $geo = Mockery::mock(LeadGeoIndex::class);
        $geo->shouldNotReceive('maybeIndex');

        $dispatch = Mockery::mock(LeadDispatchService::class);
        $dispatch->shouldNotReceive('dispatchAndAudit');

        $pipeline = new LeadProcessingPipeline(new LeadProcessingLock, $enrichment, $geo, $dispatch);
        $result = $pipeline->process('idem-busy', ['cnpj' => '12345678000199']);

        $this->assertSame('busy', $result['status']);
        $this->assertArrayNotHasKey('enrichment', $result);

        $holder->release('idem-busy');
    }
}
