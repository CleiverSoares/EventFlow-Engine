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
                ->limit(50);

            if (DB::getDriverName() !== 'sqlite') {
                $pending->lockForUpdate();
            }

            /** @var Collection<int, Export> $candidates */
            $candidates = $pending->get();
            if ($candidates->isEmpty()) {
                return null;
            }

            $tenantIds = $candidates->pluck('tenant_id')->unique()->values();
            $tenants = Tenant::query()->whereIn('id', $tenantIds)->get()->keyBy('id');

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

                $export->forceFill([
                    'status' => ExportStatus::Processing,
                    'started_at' => now(),
                ])->save();

                return $export->refresh();
            }

            return null;
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
