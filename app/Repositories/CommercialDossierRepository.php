<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommercialDossierRepository
{
    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return Collection<int, object>
     */
    public function dealsChunk(string $tenantId, ?string $afterId, int $limit, array $filters = []): Collection
    {
        $query = DB::table('crm_deals')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->limit($limit);

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        if (! empty($filters['from'])) {
            $query->where('opened_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('opened_at', '<=', $filters['to']);
        }

        return $query->get(['id', 'code', 'status', 'opened_at', 'closed_at', 'account_id']);
    }

    /**
     * @param  list<string>  $accountIds
     * @return Collection<string, object>
     */
    public function accountsByIds(string $tenantId, array $accountIds): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        return DB::table('crm_accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->get(['id', 'document', 'name'])
            ->keyBy('id');
    }

    /**
     * @param  list<string>  $dealIds
     * @return Collection<string, Collection<int, object>>
     */
    public function dealItemsGrouped(string $tenantId, array $dealIds): Collection
    {
        if ($dealIds === []) {
            return collect();
        }

        return DB::table('crm_deal_items')
            ->where('tenant_id', $tenantId)
            ->whereIn('deal_id', $dealIds)
            ->get(['deal_id', 'sku', 'quantity', 'unit_price'])
            ->groupBy('deal_id');
    }

    /**
     * @param  list<string>  $dealIds
     * @return Collection<string, int>
     */
    public function activityCountsByDeal(string $tenantId, array $dealIds): Collection
    {
        if ($dealIds === []) {
            return collect();
        }

        return DB::table('crm_activities')
            ->where('tenant_id', $tenantId)
            ->whereIn('deal_id', $dealIds)
            ->select('deal_id', DB::raw('count(*) as activity_count'))
            ->groupBy('deal_id')
            ->pluck('activity_count', 'deal_id');
    }

    /**
     * @param  list<string>  $dealIds
     * @return Collection<string, Collection<int, object>>
     */
    public function invoicesGroupedByDeal(string $tenantId, array $dealIds): Collection
    {
        if ($dealIds === []) {
            return collect();
        }

        return DB::table('crm_invoices')
            ->where('tenant_id', $tenantId)
            ->whereIn('deal_id', $dealIds)
            ->get(['id', 'deal_id', 'number', 'due_date', 'total'])
            ->groupBy('deal_id');
    }

    /**
     * @param  list<string>  $invoiceIds
     * @return Collection<string, Collection<int, object>>
     */
    public function paymentsGroupedByInvoice(string $tenantId, array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }

        return DB::table('crm_payments')
            ->where('tenant_id', $tenantId)
            ->whereIn('invoice_id', $invoiceIds)
            ->get(['invoice_id', 'status', 'amount', 'paid_at'])
            ->groupBy('invoice_id');
    }
}
