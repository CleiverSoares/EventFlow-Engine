<?php

namespace Tests\Feature;

use App\Contracts\EnrichmentClient;
use App\Contracts\TenantRateLimiter;
use App\Contracts\WebhookDispatcher;
use App\Enums\OutboxStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadIngestionPhase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_phase2_returns_202_and_persists_outbox_only(): void
    {
        config(['eventflow.mode' => 'phase2']);

        Tenant::factory()->create(['api_key' => 'ef_phase2_key']);

        $this->mock(EnrichmentClient::class, function ($mock): void {
            $mock->shouldNotReceive('enrichByCnpj');
        });

        $this->mock(WebhookDispatcher::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $this->mock(TenantRateLimiter::class, function ($mock): void {
            $mock->shouldReceive('attempt')->once()->andReturn(true);
        });

        $response = $this->withHeader('X-Api-Key', 'ef_phase2_key')
            ->postJson('/api/leads', [
                'cnpj' => '12345678000199',
                'name' => 'Phase 2 Lead',
            ]);

        $response->assertAccepted()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'phase2')
            ->assertJsonPath('status', OutboxStatus::Pending->value);

        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_phase2_returns_429_when_rate_limited(): void
    {
        config(['eventflow.mode' => 'phase2']);

        Tenant::factory()->create(['api_key' => 'ef_phase2_key']);

        $this->mock(TenantRateLimiter::class, function ($mock): void {
            $mock->shouldReceive('attempt')->once()->andReturn(false);
        });

        $this->withHeader('X-Api-Key', 'ef_phase2_key')
            ->postJson('/api/leads', ['cnpj' => '12345678000199'])
            ->assertStatus(429)
            ->assertJson(['message' => 'Too Many Requests']);

        $this->assertDatabaseCount('outbox_events', 0);
    }
}
