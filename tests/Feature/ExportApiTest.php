<?php

namespace Tests\Feature;

use App\Enums\ExportReport;
use App\Enums\ExportStatus;
use App\Enums\TenantPlan;
use App\Models\Tenant;
use App\Observability\InMemoryMetricsRegistry;
use App\Services\CrmLoadSeedService;
use App\Services\ExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_async_export_returns_202_and_worker_completes_dossier(): void
    {
        Storage::fake('local');
        config(['eventflow.exports.mode' => 'async']);

        $tenant = Tenant::factory()->create([
            'api_key' => 'ef_export_api_key',
            'plan' => TenantPlan::Pro,
        ]);

        app(CrmLoadSeedService::class)->seedTenant($tenant, 5, 2);

        $response = $this->withHeader('X-Api-Key', 'ef_export_api_key')
            ->postJson('/api/exports', [
                'report' => ExportReport::CommercialDossier->value,
                'filters' => [],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('status', ExportStatus::Pending->value);

        $exportId = $response->json('export_id');
        $processed = app(ExportService::class)->processNextFair();

        $this->assertNotNull($processed);
        $this->assertSame($exportId, $processed->id);
        $this->assertSame(ExportStatus::Completed, $processed->status);
        $this->assertGreaterThan(0, $processed->row_count);

        $this->withHeader('X-Api-Key', 'ef_export_api_key')
            ->getJson('/api/exports/'.$exportId)
            ->assertOk()
            ->assertJsonPath('data.status', ExportStatus::Completed->value);

        $metrics = app(InMemoryMetricsRegistry::class)->renderPrometheus();
        $this->assertStringContainsString('eventflow_exports_accepted_total', $metrics);
        $this->assertStringContainsString('eventflow_exports_processed_total', $metrics);
    }

    public function test_sync_export_completes_inline_for_before_demo(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create([
            'api_key' => 'ef_export_sync_key',
            'plan' => TenantPlan::Basic,
        ]);
        app(CrmLoadSeedService::class)->seedTenant($tenant, 3, 2);

        $this->withHeader('X-Api-Key', 'ef_export_sync_key')
            ->postJson('/api/exports', [
                'report' => ExportReport::CommercialDossier->value,
                'sync' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', ExportStatus::Completed->value);
    }
}
