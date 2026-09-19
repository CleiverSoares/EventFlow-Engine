<?php

namespace Tests\Feature;

use App\Contracts\EnrichmentClient;
use App\Contracts\WebhookDispatcher;
use App\Models\Tenant;
use App\Observability\InMemoryMetricsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_endpoint_exposes_prometheus_text_after_ingest(): void
    {
        app(InMemoryMetricsRegistry::class)->reset();
        config(['eventflow.mode' => 'phase1']);

        Tenant::factory()->create(['api_key' => 'ef_metrics_key']);

        $this->mock(EnrichmentClient::class, function ($mock): void {
            $mock->shouldReceive('enrichByCnpj')->once()->andReturn(['razao_social' => 'X']);
        });

        $this->mock(WebhookDispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once();
        });

        $this->withHeader('X-Api-Key', 'ef_metrics_key')
            ->postJson('/api/leads', ['cnpj' => '12345678000199'])
            ->assertCreated();

        $response = $this->get('/api/metrics');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $this->assertStringContainsString('http_requests_total', $response->getContent());
        $this->assertStringContainsString('api/leads', $response->getContent());

        app(InMemoryMetricsRegistry::class)->reset();
    }
}
