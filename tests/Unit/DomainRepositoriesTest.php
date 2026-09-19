<?php

namespace Tests\Unit;

use App\Enums\AuditStatus;
use App\Enums\OutboxStatus;
use App\Enums\TenantPlan;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Repositories\AuditLogRepository;
use App\Repositories\OutboxEventRepository;
use App\Repositories\TenantRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ValueError;

class DomainRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_plan_enum_casts_on_model(): void
    {
        $tenant = Tenant::factory()->pro()->create();

        $this->assertInstanceOf(TenantPlan::class, $tenant->plan);
        $this->assertSame(TenantPlan::Pro, $tenant->plan);
    }

    public function test_invalid_tenant_plan_is_rejected_by_enum_cast(): void
    {
        $this->expectException(ValueError::class);

        Tenant::factory()->create([
            'plan' => 'not-a-real-plan',
        ]);
    }

    public function test_tenant_repository_finds_by_api_key(): void
    {
        $tenant = Tenant::factory()->create([
            'api_key' => 'ef_test_lookup_key',
        ]);

        $found = app(TenantRepository::class)->findByApiKey('ef_test_lookup_key');

        $this->assertNotNull($found);
        $this->assertTrue($tenant->is($found));
        $this->assertNull(app(TenantRepository::class)->findByApiKey('missing'));
    }

    public function test_outbox_repository_creates_pending_and_finds_pending(): void
    {
        $repository = app(OutboxEventRepository::class);

        $created = $repository->createPending('lead.incoming', ['cnpj' => '123']);
        OutboxEvent::factory()->processed()->create();

        $pending = $repository->findPending();

        $this->assertSame(OutboxStatus::Pending, $created->status);
        $this->assertCount(1, $pending);
        $this->assertTrue($created->is($pending->first()));
    }

    public function test_audit_log_repository_creates_with_tenant_fk(): void
    {
        $tenant = Tenant::factory()->create();

        $log = app(AuditLogRepository::class)->create(
            $tenant->id,
            ['enriched' => true],
            AuditStatus::Dispatched,
        );

        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame(AuditStatus::Dispatched, $log->status);
        $this->assertTrue($log->event_payload['enriched']);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'tenant_id' => $tenant->id,
            'status' => AuditStatus::Dispatched->value,
        ]);
    }
}
