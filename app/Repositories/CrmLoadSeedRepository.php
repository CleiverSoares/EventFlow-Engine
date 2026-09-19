<?php

namespace App\Repositories;

use App\Enums\TenantPlan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class CrmLoadSeedRepository
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertAccounts(array $rows): void
    {
        if ($rows !== []) {
            DB::table('crm_accounts')->insert($rows);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertContacts(array $rows): void
    {
        if ($rows !== []) {
            DB::table('crm_contacts')->insert($rows);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $dealRows
     * @param  list<array<string, mixed>>  $itemRows
     * @param  list<array<string, mixed>>  $activityRows
     * @param  list<array<string, mixed>>  $invoiceRows
     * @param  list<array<string, mixed>>  $lineRows
     * @param  list<array<string, mixed>>  $paymentRows
     */
    public function insertDealGraphBatch(
        array $dealRows,
        array $itemRows,
        array $activityRows,
        array $invoiceRows,
        array $lineRows,
        array $paymentRows,
    ): void {
        if ($dealRows !== []) {
            DB::table('crm_deals')->insert($dealRows);
        }
        if ($itemRows !== []) {
            DB::table('crm_deal_items')->insert($itemRows);
        }
        if ($activityRows !== []) {
            DB::table('crm_activities')->insert($activityRows);
        }
        if ($invoiceRows !== []) {
            DB::table('crm_invoices')->insert($invoiceRows);
        }
        if ($lineRows !== []) {
            DB::table('crm_invoice_lines')->insert($lineRows);
        }
        if ($paymentRows !== []) {
            DB::table('crm_payments')->insert($paymentRows);
        }
    }

    public function firstOrCreateDemoTenant(string $apiKey, string $name, TenantPlan $plan): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['api_key' => $apiKey],
            ['name' => $name, 'plan' => $plan],
        );
    }
}
