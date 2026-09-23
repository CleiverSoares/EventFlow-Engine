<?php

namespace App\Repositories;

use App\Enums\ExportStatus;
use App\Enums\TenantPlan;
use App\Models\Export;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExportRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function createPending(
        string $tenantId,
        string $report,
        array $filters,
        ?string $idempotencyKey = null,
        string $format = 'csv',
    ): Export {
        return Export::query()->create([
            'tenant_id' => $tenantId,
            'report' => $report,
            'format' => $format,
            'status' => ExportStatus::Pending,
            'filters' => $filters,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function findByTenantAndIdempotencyKey(string $tenantId, string $key): ?Export
    {
        return Export::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function findForTenant(string $tenantId, string $id): ?Export
    {
        return Export::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, Export>
     */
    public function paginateForTenant(string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return Export::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(min($perPage, 100));
    }

    public function countProcessingForTenant(string $tenantId): int
    {
        return Export::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ExportStatus::Processing)
            ->count();
    }

    public function countProcessingGlobal(): int
    {
        return Export::query()
            ->where('status', ExportStatus::Processing)
            ->count();
    }

    public function hasPending(): bool
    {
        return Export::query()
            ->where('status', ExportStatus::Pending)
            ->exists();
    }

    /**
     * @return array{pending: int, processing: int, completed: int, failed: int}
     */
    public function countByStatus(): array
    {
        $rows = Export::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) ($rows[ExportStatus::Pending->value] ?? 0),
            'processing' => (int) ($rows[ExportStatus::Processing->value] ?? 0),
            'completed' => (int) ($rows[ExportStatus::Completed->value] ?? 0),
            'failed' => (int) ($rows[ExportStatus::Failed->value] ?? 0),
        ];
    }

    /**
     * @return Collection<int, Export>
     */
    public function recentForLab(int $limit = 50): Collection
    {
        return Export::query()
            ->with('tenant:id,name,plan,api_key')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object{tenant_id: string, name: string, plan: string, api_key: string, status: string, aggregate: int}>
     */
    public function statusBreakdownByTenant(): Collection
    {
        return DB::table('exports')
            ->join('tenants', 'tenants.id', '=', 'exports.tenant_id')
            ->selectRaw('tenants.id as tenant_id, tenants.name, tenants.plan, tenants.api_key, exports.status, count(*) as aggregate')
            ->groupBy('tenants.id', 'tenants.name', 'tenants.plan', 'tenants.api_key', 'exports.status')
            ->orderBy('tenants.name')
            ->get();
    }

    /**
     * Fair claim: prefer tenants under their plan cap with the oldest PENDING export.
     */
    public function claimNextFair(): ?Export
    {
        return DB::transaction(function (): ?Export {
            $globalMax = (int) config('eventflow.exports.max_processing_global', 4);
            if ($globalMax > 0 && $this->countProcessingGlobal() >= $globalMax) {
                return null;
            }

            $pending = Export::query()
                ->where('status', ExportStatus::Pending)
                ->orderBy('created_at')
                ->limit(80);

            if (DB::getDriverName() !== 'sqlite') {
                // Parallel workers must not serialize on the same row set.
                $pending->lock('FOR UPDATE SKIP LOCKED');
            }

            /** @var Collection<int, Export> $candidates */
            $candidates = $pending->get();
            if ($candidates->isEmpty()) {
                return null;
            }

            $tenantIds = $candidates->pluck('tenant_id')->unique()->values();
            $tenants = Tenant::query()->whereIn('id', $tenantIds)->get()->keyBy('id');

            $eligible = [];
            foreach ($candidates as $export) {
                /** @var Tenant|null $tenant */
                $tenant = $tenants->get($export->tenant_id);
                if ($tenant === null) {
                    continue;
                }

                $cap = $this->processingCapForPlan($tenant->plan);
                if ($this->countProcessingForTenant($tenant->id) >= $cap) {
                    continue;
                }

                $eligible[] = ['export' => $export, 'tenant' => $tenant];
            }

            if ($eligible === []) {
                return null;
            }

            usort($eligible, function (array $left, array $right): int {
                $planRank = static fn (TenantPlan $plan): int => match ($plan) {
                    TenantPlan::Basic => 0,
                    TenantPlan::Pro => 1,
                    TenantPlan::Enterprise => 2,
                };

                $byPlan = $planRank($left['tenant']->plan) <=> $planRank($right['tenant']->plan);
                if ($byPlan !== 0) {
                    return $byPlan;
                }

                return $left['export']->created_at <=> $right['export']->created_at;
            });

            /** @var Export $export */
            $export = $eligible[0]['export'];
            $export->forceFill([
                'status' => ExportStatus::Processing,
                'started_at' => now(),
            ])->save();

            return $export->refresh();
        });
    }

    public function markProcessing(Export $export): Export
    {
        $export->forceFill([
            'status' => ExportStatus::Processing,
            'started_at' => now(),
        ])->save();

        return $export->refresh();
    }

    public function markCompleted(Export $export, string $filePath, int $rowCount): Export
    {
        $export->forceFill([
            'status' => ExportStatus::Completed,
            'file_path' => $filePath,
            'row_count' => $rowCount,
            'finished_at' => now(),
            'error_message' => null,
        ])->save();

        return $export->refresh();
    }

    public function markFailed(Export $export, string $message): Export
    {
        $export->forceFill([
            'status' => ExportStatus::Failed,
            'error_message' => $message,
            'finished_at' => now(),
        ])->save();

        return $export->refresh();
    }

    private function processingCapForPlan(TenantPlan $plan): int
    {
        return match ($plan) {
            TenantPlan::Basic => (int) config('eventflow.exports.max_processing_basic', 1),
            TenantPlan::Pro => (int) config('eventflow.exports.max_processing_pro', 2),
            TenantPlan::Enterprise => (int) config('eventflow.exports.max_processing_enterprise', 3),
        };
    }
}
