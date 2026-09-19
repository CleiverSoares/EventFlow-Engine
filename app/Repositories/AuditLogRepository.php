<?php

namespace App\Repositories;

use App\Enums\AuditStatus;
use App\Models\AuditLog;

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
}
