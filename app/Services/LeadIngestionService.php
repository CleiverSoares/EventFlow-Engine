<?php

namespace App\Services;

use App\Contracts\EnrichmentClient;
use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Repositories\AuditLogRepository;
use RuntimeException;

class LeadIngestionService
{
    public function __construct(
        private EnrichmentClient $enrichmentClient,
        private WebhookDispatcher $webhookDispatcher,
        private AuditLogRepository $auditLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{audit: AuditLog, enriched: array<string, mixed>}
     */
    public function ingest(Tenant $tenant, array $payload): array
    {
        if (config('eventflow.mode') !== 'phase1') {
            throw new RuntimeException('Only phase1 ingest is available in this slice.');
        }

        $enriched = [
            'tenant_id' => $tenant->id,
            'received' => $payload,
            'enrichment' => $this->enrichmentClient->enrichByCnpj((string) $payload['cnpj']),
        ];

        try {
            $this->webhookDispatcher->dispatch($enriched);

            $audit = $this->auditLogs->create(
                $tenant->id,
                $enriched,
                AuditStatus::Dispatched,
            );

            return [
                'audit' => $audit,
                'enriched' => $enriched,
            ];
        } catch (RuntimeException $exception) {
            $this->auditLogs->create(
                $tenant->id,
                [
                    ...$enriched,
                    'error' => $exception->getMessage(),
                ],
                AuditStatus::Error,
            );

            throw $exception;
        }
    }
}
