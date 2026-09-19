<?php

namespace Tests\Unit;

use App\Contracts\EnrichmentClient;
use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Repositories\AuditLogRepository;
use App\Services\LeadIngestionService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LeadIngestionServiceTest extends TestCase
{
    public function test_happy_path_enriches_dispatches_and_audits(): void
    {
        $tenant = new Tenant(['name' => 'Acme', 'api_key' => 'k', 'plan' => 'pro']);
        $tenant->id = '22222222-2222-2222-2222-222222222222';

        $enrichment = Mockery::mock(EnrichmentClient::class);
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

        config(['eventflow.mode' => 'phase1']);

        $service = new LeadIngestionService($enrichment, $webhook, $audits);
        $result = $service->ingest($tenant, ['cnpj' => '12345678000199']);

        $this->assertSame($audit->id, $result['audit']->id);
        $this->assertSame('ACME LTDA', $result['enriched']['enrichment']['razao_social']);
    }

    public function test_records_audit_error_when_webhook_fails(): void
    {
        $tenant = new Tenant(['name' => 'Acme', 'api_key' => 'k', 'plan' => 'basic']);
        $tenant->id = '22222222-2222-2222-2222-222222222222';

        $enrichment = Mockery::mock(EnrichmentClient::class);
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

        config(['eventflow.mode' => 'phase1']);

        $service = new LeadIngestionService($enrichment, $webhook, $audits);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Webhook down');

        $service->ingest($tenant, ['cnpj' => '12345678000199']);
    }
}
