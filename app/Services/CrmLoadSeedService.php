<?php

namespace App\Services;

use App\Enums\TenantPlan;
use App\Models\Tenant;
use App\Repositories\CrmLoadSeedRepository;
use Illuminate\Support\Str;

class CrmLoadSeedService
{
    public function __construct(private CrmLoadSeedRepository $seeds) {}

    /**
     * Seed a tenant with a nested CRM graph sized by deal count (heavy JOIN target).
     */
    public function seedTenant(Tenant $tenant, int $dealCount, int $accounts = 50): int
    {
        $dealCount = max(1, $dealCount);
        $accounts = max(1, min($accounts, $dealCount));

        $accountIds = [];
        $now = now()->toDateTimeString();
        $accountRows = [];
        $contactRows = [];

        for ($i = 0; $i < $accounts; $i++) {
            $id = (string) Str::uuid();
            $accountIds[] = $id;
            $accountRows[] = [
                'id' => $id,
                'tenant_id' => $tenant->id,
                'document' => str_pad((string) ($i + 1), 14, '0', STR_PAD_LEFT),
                'name' => 'Account '.$i.' '.$tenant->name,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $contactRows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'account_id' => $id,
                'name' => 'Contact '.$i,
                'email' => "c{$i}@example.test",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->seeds->insertAccounts($accountRows);
        $this->seeds->insertContacts($contactRows);

        $batch = 200;
        $created = 0;

        while ($created < $dealCount) {
            $take = min($batch, $dealCount - $created);
            $dealRows = [];
            $itemRows = [];
            $activityRows = [];
            $invoiceRows = [];
            $lineRows = [];
            $paymentRows = [];

            for ($i = 0; $i < $take; $i++) {
                $n = $created + $i;
                $dealId = (string) Str::uuid();
                $accountId = $accountIds[$n % count($accountIds)];
                $opened = now()->subDays($n % 365);

                $dealRows[] = [
                    'id' => $dealId,
                    'tenant_id' => $tenant->id,
                    'account_id' => $accountId,
                    'code' => 'D-'.$n,
                    'status' => $n % 5 === 0 ? 'won' : 'open',
                    'opened_at' => $opened->toDateTimeString(),
                    'closed_at' => $n % 5 === 0 ? $opened->copy()->addDays(10)->toDateTimeString() : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $itemRows[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'deal_id' => $dealId,
                    'sku' => 'SKU-'.($n % 100),
                    'quantity' => ($n % 5) + 1,
                    'unit_price' => 10 + ($n % 50),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $activityRows[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'deal_id' => $dealId,
                    'type' => 'call',
                    'subject' => 'Follow-up '.$n,
                    'occurred_at' => $opened->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $invoiceId = (string) Str::uuid();
                $total = (10 + ($n % 50)) * (($n % 5) + 1);
                $invoiceRows[] = [
                    'id' => $invoiceId,
                    'tenant_id' => $tenant->id,
                    'deal_id' => $dealId,
                    'number' => 'INV-'.$n,
                    'due_date' => $opened->copy()->addDays(30)->toDateString(),
                    'total' => $total,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $lineRows[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoiceId,
                    'description' => 'Line '.$n,
                    'quantity' => ($n % 5) + 1,
                    'unit_price' => 10 + ($n % 50),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $paymentRows[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoiceId,
                    'status' => $n % 3 === 0 ? 'paid' : 'pending',
                    'amount' => $total,
                    'paid_at' => $n % 3 === 0 ? $opened->copy()->addDays(5)->toDateTimeString() : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->seeds->insertDealGraphBatch(
                $dealRows,
                $itemRows,
                $activityRows,
                $invoiceRows,
                $lineRows,
                $paymentRows,
            );

            $created += $take;
        }

        return $created;
    }

    /**
     * @return array{whale: Tenant, lightA: Tenant, lightB: Tenant}
     */
    public function ensureDemoTenants(): array
    {
        $whale = $this->seeds->firstOrCreateDemoTenant(
            'ef_export_whale_key',
            'Export Whale Co',
            TenantPlan::Enterprise,
        );
        $lightA = $this->seeds->firstOrCreateDemoTenant(
            'ef_export_light_a_key',
            'Export Light A',
            TenantPlan::Basic,
        );
        $lightB = $this->seeds->firstOrCreateDemoTenant(
            'ef_export_light_b_key',
            'Export Light B',
            TenantPlan::Pro,
        );

        return compact('whale', 'lightA', 'lightB');
    }
}
