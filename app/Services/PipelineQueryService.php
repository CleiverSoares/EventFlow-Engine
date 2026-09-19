<?php

namespace App\Services;

use App\Enums\AuditStatus;
use App\Enums\OutboxStatus;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Repositories\AuditLogRepository;
use App\Repositories\OutboxEventRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PipelineQueryService
{
    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private AuditLogRepository $auditLogs,
    ) {}

    /**
     * @return LengthAwarePaginator<int, OutboxEvent>
     */
    public function listOutbox(string $tenantId, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $enum = $status !== null && $status !== ''
            ? OutboxStatus::from($status)
            : null;

        return $this->outboxEvents->paginateForTenant($tenantId, $enum, min($perPage, 100));
    }

    public function getOutbox(string $tenantId, string $id): ?OutboxEvent
    {
        return $this->outboxEvents->findForTenant($tenantId, $id);
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function listAuditLogs(string $tenantId, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $enum = $status !== null && $status !== ''
            ? AuditStatus::from($status)
            : null;

        return $this->auditLogs->paginateForTenant($tenantId, $enum, min($perPage, 100));
    }

    public function getAuditLog(string $tenantId, string $id): ?AuditLog
    {
        return $this->auditLogs->findForTenant($tenantId, $id);
    }
}
