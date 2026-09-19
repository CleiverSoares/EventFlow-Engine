<?php

namespace Tests\Unit;

use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Enums\CircuitState;
use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use App\Services\Dispatch\HttpWebhookDispatcher;
use App\Services\LeadDispatchService;
use App\Services\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LeadDispatchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'eventflow.circuit_breaker.failure_threshold' => 2,
            'eventflow.circuit_breaker.recovery_timeout_seconds' => 30,
            'eventflow.webhook.url' => 'https://hooks.example.test/leads',
            'eventflow.webhook.timeout_seconds' => 5,
        ]);
    }

    public function test_dispatcher_success_writes_dispatched_audit(): void
    {
        $webhook = Mockery::mock(WebhookDispatcher::class);
        $webhook->shouldReceive('dispatch')->once();

        $audit = new AuditLog([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'event_payload' => [],
            'status' => AuditStatus::Dispatched,
        ]);
        $audit->id = '22222222-2222-2222-2222-222222222222';

        $audits = Mockery::mock(AuditLogRepository::class);
        $audits->shouldReceive('create')
            ->once()
            ->withArgs(fn (string $tenantId, array $payload, AuditStatus $status): bool => $tenantId === '11111111-1111-1111-1111-111111111111'
                && $status === AuditStatus::Dispatched)
            ->andReturn($audit);

        $service = new LeadDispatchService($webhook, $audits);
        $result = $service->dispatchAndAudit('11111111-1111-1111-1111-111111111111', [
            'enrichment' => ['razao_social' => 'ACME'],
        ]);

        $this->assertSame($audit->id, $result->id);
    }

    public function test_dispatcher_failure_writes_error_audit_and_counts_cb_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'hooks.example.test/*' => Http::response(['error' => 'down'], 500),
        ]);

        $audits = Mockery::mock(AuditLogRepository::class);
        $audits->shouldReceive('create')
            ->once()
            ->withArgs(fn (string $tenantId, array $payload, AuditStatus $status): bool => $status === AuditStatus::Error
                && isset($payload['error']))
            ->andReturn(new AuditLog([
                'tenant_id' => '11111111-1111-1111-1111-111111111111',
                'event_payload' => [],
                'status' => AuditStatus::Error,
            ]));

        $service = new LeadDispatchService(new HttpWebhookDispatcher, $audits);

        try {
            $service->dispatchAndAudit('11111111-1111-1111-1111-111111111111', [
                'enrichment' => ['razao_social' => 'ACME'],
            ]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        $breaker = new CircuitBreaker('webhook');
        $this->assertSame(1, (int) Cache::get('eventflow:cb:webhook:failures'));
        $this->assertSame(CircuitState::Closed, $breaker->state());
    }
}
