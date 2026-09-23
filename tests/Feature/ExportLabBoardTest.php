<?php

namespace Tests\Feature;

use App\Enums\ExportStatus;
use App\Enums\TenantPlan;
use App\Events\ExportUpdated;
use App\Models\Export;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ExportLabBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_lab_page_renders_with_snapshot(): void
    {
        $this->withoutVite();

        $tenant = Tenant::factory()->create([
            'name' => 'Board Tenant',
            'plan' => TenantPlan::Basic,
            'api_key' => 'ef_board_key',
        ]);

        Export::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => ExportStatus::Pending,
        ]);

        $this->get('/lab/exports')
            ->assertOk()
            ->assertSee('Exports lab', false)
            ->assertSee('Board Tenant', false)
            ->assertSee('exports.lab', false);
    }

    public function test_snapshot_json_includes_totals_and_tenants(): void
    {
        $tenant = Tenant::factory()->create(['plan' => TenantPlan::Pro]);
        Export::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => ExportStatus::Processing,
        ]);

        $this->getJson('/lab/exports/snapshot')
            ->assertOk()
            ->assertJsonPath('totals.processing', 1)
            ->assertJsonPath('tenants.0.processing', 1)
            ->assertJsonPath('tenants.0.pending', 0)
            ->assertJsonStructure([
                'totals' => ['pending', 'processing', 'completed', 'failed'],
                'tenants',
                'recent',
                'generated_at',
            ]);
    }

    public function test_snapshot_maps_uppercase_db_status_to_tenant_counters(): void
    {
        $tenant = Tenant::factory()->create(['plan' => TenantPlan::Enterprise]);
        Export::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'status' => ExportStatus::Completed,
        ]);
        Export::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => ExportStatus::Pending,
        ]);

        $this->getJson('/lab/exports/snapshot')
            ->assertOk()
            ->assertJsonPath('totals.completed', 2)
            ->assertJsonPath('totals.pending', 1)
            ->assertJsonPath('tenants.0.completed', 2)
            ->assertJsonPath('tenants.0.pending', 1);
    }

    public function test_export_request_dispatches_broadcast_event(): void
    {
        Event::fake([ExportUpdated::class]);

        $tenant = Tenant::factory()->create([
            'api_key' => 'ef_board_broadcast',
            'plan' => TenantPlan::Basic,
        ]);

        $this->withHeader('X-Api-Key', 'ef_board_broadcast')
            ->postJson('/api/exports', [
                'report' => 'commercial_dossier',
                'sync' => true,
            ]);

        Event::assertDispatched(ExportUpdated::class);
    }
}
