<?php

namespace Tests\Unit;

use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Enums\OutboxStatus;
use App\Enums\TenantPlan;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Observability\Tracing;
use App\Repositories\AuditLogRepository;
use App\Repositories\OutboxEventRepository;
use App\Services\LeadEnrichmentService;
use App\Services\LeadIngestionService;
use App\Services\RateLimiting\CacheTenantRateLimiter;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LeadIngestionServiceTest extends TestCase
{
    private function tracing(): Tracing
    {
        return app(Tracing::class);
    }

    public function test_happy_path_enriches_dispatches_and_audits(): void
    {
        $tenant = new Tenant(['name' => 'Acme', 'api_key' => 'k', 'plan' => 'pro']);
        $tenant->id = '22222222-2222-2222-2222-222222222222';

        $enrichment = Mockery::mock(LeadEnrichmentService::class);
        $enrichment->shouldReceive('enrichByCnpj')
            ->once()
            ->with('12345678000199')
            ->andReturn(['razao_social' => 'ACME LTDA']);

        $webhook = Mockery::mock(WebhookDispatcher::class);
        $webhook->shouldReceive('dispatch')->once();

        $audit = new AuditLog([
            'tenant_id' => $tenant->id,
            'event_payload' => [],
            'status' => AuditStatus::Dispatched,
        ]);
        $audit->id = '33333333-3333-3333-3333-333333333333';

        $audits = Mockery::mock(AuditLogRepository::class);
        $audits->shouldReceive('create')
            ->once()
            ->withArgs(function (string $tenantId, array $payload, AuditStatus $status) use ($tenant): bool {
                return $tenantId === $tenant->id
                    && $status === AuditStatus::Dispatched
                    && ($payload['enrichment']['razao_social'] ?? null) === 'ACME LTDA';
            })
            ->andReturn($audit);

        $outbox = Mockery::mock(OutboxEventRepository::class);
        $outbox->shouldNotReceive('createPending');

        config(['eventflow.mode' => 'phase1']);

        $service = new LeadIngestionService($enrichment, $webhook, $audits, $outbox, $this->tracing());
        $result = $service->ingest($tenant, ['cnpj' => '12345678000199']);

        $this->assertSame($audit->id, $result['audit']->id);
        $this->assertSame('ACME LTDA', $result['enriched']['enrichment']['razao_social']);
    }

    public function test_records_audit_error_when_webhook_fails(): void
    {
        $tenant = new Tenant(['name' => 'Acme', 'api_key' => 'k', 'plan' => 'basic']);
        $tenant->id = '22222222-2222-2222-2222-222222222222';

        $enrichment = Mockery::mock(LeadEnrichmentService::class);
        $enrichment->shouldReceive('enrichByCnpj')->once()->andReturn(['razao_social' => 'ACME']);

        $webhook = Mockery::mock(WebhookDispatcher::class);
        $webhook->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Webhook down'));

        $errorAudit = new AuditLog([
            'tenant_id' => $tenant->id,
            'event_payload' => [],
            'status' => AuditStatus::Error,
        ]);
        $errorAudit->id = '44444444-4444-4444-4444-444444444444';

        $audits = Mockery::mock(AuditLogRepository::class);
        $audits->shouldReceive('create')
            ->once()
            ->withArgs(fn (string $tenantId, array $payload, AuditStatus $status): bool => $status === AuditStatus::Error
                && ($payload['error'] ?? null) === 'Webhook down')
            ->andReturn($errorAudit);

        $outbox = Mockery::mock(OutboxEventRepository::class);
        $outbox->shouldNotReceive('createPending');

        config(['eventflow.mode' => 'phase1']);

        $service = new LeadIngestionService($enrichment, $webhook, $audits, $outbox, $this->tracing());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Webhook down');

        $service->ingest($tenant, ['cnpj' => '12345678000199']);
    }

    public function test_phase2_creates_outbox_without_enrichment_or_webhook(): void
    {
        $tenant = new Tenant(['name' => 'Acme', 'api_key' => 'k', 'plan' => 'pro']);
        $tenant->id = '22222222-2222-2222-2222-222222222222';

        $enrichment = Mockery::mock(LeadEnrichmentService::class);
        $enrichment->shouldNotReceive('enrichByCnpj');

        $webhook = Mockery::mock(WebhookDispatcher::class);
        $webhook->shouldNotReceive('dispatch');

        $audits = Mockery::mock(AuditLogRepository::class);
        $audits->shouldNotReceive('create');

        $outboxEvent = new OutboxEvent([
            'aggregate_type' => 'lead.incoming',
            'payload' => [],
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
        ]);
        $outboxEvent->id = '55555555-5555-5555-5555-555555555555';

        $outbox = Mockery::mock(OutboxEventRepository::class);
        $outbox->shouldReceive('createPending')
            ->once()
            ->with(
                $tenant->id,
                'lead.incoming',
                Mockery::on(fn (array $payload): bool => ($payload['tenant_id'] ?? null) === $tenant->id),
                null,
            )
            ->andReturn($outboxEvent);

        config(['eventflow.mode' => 'phase2']);
        config(['eventflow.outbox.max_pending' => 0]);
        config(['eventflow.outbox.max_pending_per_tenant' => 0]);

        $outbox->shouldReceive('countPending')->never();
        $outbox->shouldReceive('countPendingForTenant')->never();
        $outbox->shouldReceive('findByTenantAndIdempotencyKey')->never();

        $service = new LeadIngestionService($enrichment, $webhook, $audits, $outbox, $this->tracing());
        $result = $service->ingest($tenant, ['cnpj' => '12345678000199']);

        $this->assertSame('phase2', $result['mode']);
        $this->assertSame($outboxEvent->id, $result['outbox']->id);
    }
}

class CacheTenantRateLimiterTest extends TestCase
{
    public function test_allows_under_ceiling_and_blocks_when_exceeded(): void
    {
        config([
            'eventflow.rate_limits.basic' => 2,
            'eventflow.redis.rate_prefix' => 'eventflow:rate:test:',
        ]);

        $tenant = new Tenant(['name' => 'Acme', 'api_key' => 'k', 'plan' => TenantPlan::Basic]);
        $tenant->id = '66666666-6666-6666-6666-666666666666';

        RateLimiter::clear(config('eventflow.redis.rate_prefix').$tenant->id);

        $limiter = new CacheTenantRateLimiter;

        $this->assertTrue($limiter->attempt($tenant));
        $this->assertTrue($limiter->attempt($tenant));
        $this->assertFalse($limiter->attempt($tenant));
    }
}
