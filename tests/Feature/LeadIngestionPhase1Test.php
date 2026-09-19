<?php

namespace Tests\Feature;

use App\Contracts\EnrichmentClient;
use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LeadIngestionPhase1Test extends TestCase
{
    use RefreshDatabase;

    public function test_validation_rejects_malformed_payload(): void
    {
        $tenant = Tenant::factory()->create(['api_key' => 'ef_phase1_key']);

        $this->withHeader('X-Api-Key', 'ef_phase1_key')
            ->postJson('/api/leads', ['cnpj' => '123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cnpj']);

        $this->assertDatabaseMissing('audit_logs', [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_authenticated_post_returns_success_envelope_in_phase1(): void
    {
        config(['eventflow.mode' => 'phase1']);

        Tenant::factory()->create(['api_key' => 'ef_phase1_key']);

        $this->mock(EnrichmentClient::class, function ($mock): void {
            $mock->shouldReceive('enrichByCnpj')
                ->once()
                ->andReturn(['razao_social' => 'Empresa Teste']);
        });

        $this->mock(WebhookDispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once();
        });

        $response = $this->withHeader('X-Api-Key', 'ef_phase1_key')
            ->postJson('/api/leads', [
                'cnpj' => '12.345.678/0001-99',
                'name' => 'Lead Test',
            ]);

        $response->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'phase1')
            ->assertJsonPath('status', AuditStatus::Dispatched->value);

        $this->assertDatabaseHas('audit_logs', [
            'status' => AuditStatus::Dispatched->value,
        ]);
    }

    public function test_webhook_failure_returns_502_and_writes_error_audit(): void
    {
        config(['eventflow.mode' => 'phase1']);

        $tenant = Tenant::factory()->create(['api_key' => 'ef_phase1_key']);

        $this->mock(EnrichmentClient::class, function ($mock): void {
            $mock->shouldReceive('enrichByCnpj')->once()->andReturn(['razao_social' => 'X']);
        });

        $this->mock(WebhookDispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Webhook down'));
        });

        $this->withHeader('X-Api-Key', 'ef_phase1_key')
            ->postJson('/api/leads', ['cnpj' => '12345678000199'])
            ->assertStatus(502);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'status' => AuditStatus::Error->value,
        ]);
    }
}
