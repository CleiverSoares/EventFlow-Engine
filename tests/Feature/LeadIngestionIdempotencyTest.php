<?php

namespace Tests\Feature;

use App\Contracts\EnrichmentClient;
use App\Contracts\TenantRateLimiter;
use App\Contracts\WebhookDispatcher;
use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadIngestionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase2_replays_same_outbox_for_idempotency_key(): void
    {
        config(['eventflow.mode' => 'phase2']);

        Tenant::factory()->create(['api_key' => 'ef_idem_key']);

        $this->mock(EnrichmentClient::class, function ($mock): void {
            $mock->shouldNotReceive('enrichByCnpj');
        });
        $this->mock(WebhookDispatcher::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });
        $this->mock(TenantRateLimiter::class, function ($mock): void {
            $mock->shouldReceive('attempt')->twice()->andReturn(true);
        });

        $first = $this->withHeaders([
            'X-Api-Key' => 'ef_idem_key',
            'Idempotency-Key' => 'lead-batch-1',
        ])->postJson('/api/leads', ['cnpj' => '12345678000199']);

        $first->assertAccepted()->assertJsonPath('idempotent_replay', false);
        $outboxId = $first->json('outbox_id');

        $second = $this->withHeaders([
            'X-Api-Key' => 'ef_idem_key',
            'Idempotency-Key' => 'lead-batch-1',
        ])->postJson('/api/leads', ['cnpj' => '12345678000199']);

        $second->assertAccepted()
            ->assertJsonPath('outbox_id', $outboxId)
            ->assertJsonPath('idempotent_replay', true);

        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_phase2_returns_503_when_global_pending_backpressure_hits(): void
    {
        config([
            'eventflow.mode' => 'phase2',
            'eventflow.outbox.max_pending' => 1,
        ]);

        $tenant = Tenant::factory()->create(['api_key' => 'ef_bp_key']);
        OutboxEvent::factory()->pending()->create(['tenant_id' => $tenant->id]);

        $this->mock(TenantRateLimiter::class, function ($mock): void {
            $mock->shouldReceive('attempt')->once()->andReturn(true);
        });

        $this->withHeader('X-Api-Key', 'ef_bp_key')
            ->postJson('/api/leads', ['cnpj' => '12345678000199'])
            ->assertStatus(503);

        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_phase2_stores_tenant_id_on_outbox(): void
    {
        config(['eventflow.mode' => 'phase2']);

        $tenant = Tenant::factory()->create(['api_key' => 'ef_tenant_col_key']);

        $this->mock(TenantRateLimiter::class, function ($mock): void {
            $mock->shouldReceive('attempt')->once()->andReturn(true);
        });

        $this->withHeader('X-Api-Key', 'ef_tenant_col_key')
            ->postJson('/api/leads', ['cnpj' => '12345678000199'])
            ->assertAccepted();

        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => $tenant->id,
            'status' => OutboxStatus::Pending->value,
        ]);
    }
}
