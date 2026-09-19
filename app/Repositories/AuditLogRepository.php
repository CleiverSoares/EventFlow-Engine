<?php

namespace App\Repositories;

use App\Enums\AuditStatus;
use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditLogRepository
{
    /**
     * @param  array<string, mixed>  $eventPayload
     */
    public function create(string $tenantId, array $eventPayload, AuditStatus $status): AuditLog
    {
        return AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'event_payload' => $eventPayload,
            'status' => $status,
        ]);
    }

    public function findForTenant(string $tenantId, string $id): ?AuditLog
    {
        return AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginateForTenant(
        string $tenantId,
        ?AuditStatus $status = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }
}
