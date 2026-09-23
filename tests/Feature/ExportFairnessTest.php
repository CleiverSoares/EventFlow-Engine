<?php

namespace Tests\Feature;

use App\Enums\ExportReport;
use App\Enums\ExportStatus;
use App\Enums\TenantPlan;
use App\Models\Export;
use App\Models\Tenant;
use App\Repositories\ExportRepository;
use App\Services\CrmLoadSeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportFairnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_prefers_tenant_with_free_slot_over_saturated_whale(): void
    {
        $whale = Tenant::factory()->create(['plan' => TenantPlan::Enterprise]);
        $light = Tenant::factory()->create(['plan' => TenantPlan::Basic]);

        config([
            'eventflow.exports.max_processing_global' => 4,
            'eventflow.exports.max_processing_enterprise' => 1,
            'eventflow.exports.max_processing_basic' => 1,
        ]);

        Export::factory()->create([
            'tenant_id' => $whale->id,
            'report' => ExportReport::CommercialDossier,
            'status' => ExportStatus::Processing,
        ]);

        $lightPending = Export::factory()->create([
            'tenant_id' => $light->id,
            'report' => ExportReport::CommercialDossier,
            'status' => ExportStatus::Pending,
            'created_at' => now()->subMinute(),
        ]);

        Export::factory()->create([
            'tenant_id' => $whale->id,
            'report' => ExportReport::CommercialDossier,
            'status' => ExportStatus::Pending,
            'created_at' => now()->subMinutes(10),
        ]);

        $claimed = app(ExportRepository::class)->claimNextFair();

        $this->assertNotNull($claimed);
        $this->assertSame($lightPending->id, $claimed->id);
        $this->assertSame(ExportStatus::Processing, $claimed->status);
    }

    public function test_claim_prefers_light_plan_when_whale_also_under_cap(): void
    {
        $whale = Tenant::factory()->create(['plan' => TenantPlan::Enterprise]);
        $light = Tenant::factory()->create(['plan' => TenantPlan::Basic]);

        config([
            'eventflow.exports.max_processing_global' => 8,
            'eventflow.exports.max_processing_enterprise' => 4,
            'eventflow.exports.max_processing_basic' => 2,
        ]);

        Export::factory()->create([
            'tenant_id' => $whale->id,
            'status' => ExportStatus::Pending,
            'created_at' => now()->subMinutes(10),
        ]);

        $lightPending = Export::factory()->create([
            'tenant_id' => $light->id,
            'status' => ExportStatus::Pending,
            'created_at' => now()->subMinute(),
        ]);

        $claimed = app(ExportRepository::class)->claimNextFair();

        $this->assertNotNull($claimed);
        $this->assertSame($lightPending->id, $claimed->id);
    }

    public function test_seed_creates_multi_table_rows_for_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $deals = app(CrmLoadSeedService::class)->seedTenant($tenant, 4, 2);

        $this->assertSame(4, $deals);
        $this->assertDatabaseCount('crm_deals', 4);
        $this->assertDatabaseCount('crm_deal_items', 4);
        $this->assertDatabaseCount('crm_invoices', 4);
        $this->assertDatabaseCount('crm_payments', 4);
    }
}
