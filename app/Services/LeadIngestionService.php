<?php

namespace App\Services;

use App\Contracts\WebhookDispatcher;
use App\Enums\AuditStatus;
use App\Exceptions\OutboxBackpressureException;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Observability\Tracing;
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
        private Tracing $tracing,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     mode: string,
     *     audit?: AuditLog,
     *     enriched?: array<string, mixed>,
     *     outbox?: OutboxEvent,
     *     idempotent_replay?: bool
     * }
     */
    public function ingest(Tenant $tenant, array $payload, ?string $idempotencyKey = null): array
    {
        return match ((string) config('eventflow.mode')) {
            'phase1' => $this->ingestPhase1($tenant, $payload),
            'phase2' => $this->ingestPhase2($tenant, $payload, $idempotencyKey),
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
     * @return array{mode: string, outbox: OutboxEvent, idempotent_replay?: bool}
     */
    private function ingestPhase2(Tenant $tenant, array $payload, ?string $idempotencyKey): array
    {
        $this->tracing->withTenant($tenant->id);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->outboxEvents->findByTenantAndIdempotencyKey($tenant->id, $idempotencyKey);
            if ($existing !== null) {
                return [
                    'mode' => 'phase2',
                    'outbox' => $existing,
                    'idempotent_replay' => true,
                ];
            }
        }

        $this->assertOutboxCapacity($tenant->id);

        $outbox = DB::transaction(function () use ($tenant, $payload, $idempotencyKey): OutboxEvent {
            $trace = $this->tracing->current();

            return $this->outboxEvents->createPending(
                $tenant->id,
                'lead.incoming',
                [
                    'tenant_id' => $tenant->id,
                    'received' => $payload,
                    'traceparent' => $trace?->toTraceparent(),
                ],
                $idempotencyKey !== '' ? $idempotencyKey : null,
            );
        });

        return [
            'mode' => 'phase2',
            'outbox' => $outbox,
        ];
    }

    private function assertOutboxCapacity(string $tenantId): void
    {
        $globalMax = (int) config('eventflow.outbox.max_pending', 0);
        if ($globalMax > 0 && $this->outboxEvents->countPending() >= $globalMax) {
            throw new OutboxBackpressureException;
        }

        $tenantMax = (int) config('eventflow.outbox.max_pending_per_tenant', 0);
        if ($tenantMax > 0 && $this->outboxEvents->countPendingForTenant($tenantId) >= $tenantMax) {
            throw new OutboxBackpressureException('Tenant outbox backlog is too high. Retry later.');
        }
    }
}
