<?php

namespace App\Services;

use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Repositories\AuditLogRepository;
use App\Repositories\OutboxEventRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeadIngestionService
{
    public function __construct(
        private LeadEnrichmentService $enrichment,
        private WebhookDispatcher $webhookDispatcher,
        private AuditLogRepository $auditLogs,
        private OutboxEventRepository $outboxEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     mode: string,
     *     audit?: AuditLog,
     *     enriched?: array<string, mixed>,
     *     outbox?: OutboxEvent
     * }
     */
    public function ingest(Tenant $tenant, array $payload): array
    {
        return match ((string) config('eventflow.mode')) {
            'phase1' => $this->ingestPhase1($tenant, $payload),
            'phase2' => $this->ingestPhase2($tenant, $payload),
            default => throw new RuntimeException('Unsupported EVENTFLOW_MODE.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{mode: string, audit: AuditLog, enriched: array<string, mixed>}
     */
    private function ingestPhase1(Tenant $tenant, array $payload): array
    {
        $enriched = [
            'tenant_id' => $tenant->id,
            'received' => $payload,
            'enrichment' => $this->enrichment->enrichByCnpj((string) $payload['cnpj']),
        ];

        try {
            $this->webhookDispatcher->dispatch($enriched);

            $audit = $this->auditLogs->create(
                $tenant->id,
                $enriched,
                AuditStatus::Dispatched,
            );

            return [
                'mode' => 'phase1',
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array{mode: string, outbox: OutboxEvent}
     */
    private function ingestPhase2(Tenant $tenant, array $payload): array
    {
        $outbox = DB::transaction(function () use ($tenant, $payload): OutboxEvent {
            return $this->outboxEvents->createPending('lead.incoming', [
                'tenant_id' => $tenant->id,
                'received' => $payload,
            ]);
        });

        return [
            'mode' => 'phase2',
            'outbox' => $outbox,
        ];
    }
}
