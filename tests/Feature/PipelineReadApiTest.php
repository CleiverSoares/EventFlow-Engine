<?php

namespace Tests\Feature;

use App\Enums\AuditStatus;
use App\Enums\OutboxStatus;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_shows_outbox_scoped_to_tenant(): void
    {
        $tenant = Tenant::factory()->create(['api_key' => 'ef_read_a']);
        $other = Tenant::factory()->create(['api_key' => 'ef_read_b']);

        $mine = OutboxEvent::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => OutboxStatus::Pending,
        ]);
        OutboxEvent::factory()->create([
            'tenant_id' => $other->id,
            'status' => OutboxStatus::Pending,
        ]);

        $this->withHeader('X-Api-Key', 'ef_read_a')
            ->getJson('/api/outbox')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->withHeader('X-Api-Key', 'ef_read_a')
            ->getJson('/api/outbox/'.$mine->id)
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);

        $this->withHeader('X-Api-Key', 'ef_read_a')
            ->getJson('/api/outbox/'.OutboxEvent::factory()->create(['tenant_id' => $other->id])->id)
            ->assertNotFound();
    }

    public function test_lists_and_shows_audit_logs_scoped_to_tenant(): void
    {
        $tenant = Tenant::factory()->create(['api_key' => 'ef_audit_a']);
        $other = Tenant::factory()->create(['api_key' => 'ef_audit_b']);

        $mine = AuditLog::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => AuditStatus::Dispatched,
        ]);
        AuditLog::factory()->create([
            'tenant_id' => $other->id,
            'status' => AuditStatus::Dispatched,
        ]);

        $this->withHeader('X-Api-Key', 'ef_audit_a')
            ->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->withHeader('X-Api-Key', 'ef_audit_a')
            ->getJson('/api/audit-logs/'.$mine->id)
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);
    }

    public function test_health_endpoint_reports_database_when_optional_checks_disabled(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checks.database.ok', true);
    }
}
