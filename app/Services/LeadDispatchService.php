<?php

namespace App\Services;

use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use RuntimeException;
use Throwable;

class LeadDispatchService
{
    public function __construct(
        private WebhookDispatcher $webhookDispatcher,
        private AuditLogRepository $auditLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $enriched
     */
    public function dispatchAndAudit(string $tenantId, array $enriched): AuditLog
    {
        try {
            $this->webhookDispatcher->dispatch($enriched);

            return $this->auditLogs->create($tenantId, $enriched, AuditStatus::Dispatched);
        } catch (Throwable $exception) {
            $this->auditLogs->create(
                $tenantId,
                [
                    ...$enriched,
                    'error' => $exception->getMessage(),
                ],
                AuditStatus::Error,
            );

            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }
}
