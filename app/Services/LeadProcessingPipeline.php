<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Services\Geo\LeadGeoIndex;
use App\Services\Locking\LeadProcessingLock;

/**
 * Phase 2 worker entry: lock → enrich → geo → dispatch/audit.
 */
class LeadProcessingPipeline
{
    public function __construct(
        private LeadProcessingLock $lock,
        private LeadEnrichmentService $enrichment,
        private LeadGeoIndex $geo,
        private LeadDispatchService $dispatch,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     status: 'processed'|'busy',
     *     enrichment?: array<string, mixed>,
     *     geo_indexed?: bool,
     *     audit?: AuditLog
     * }
     */
    public function process(string $idempotencyKey, array $payload): array
    {
        $enrichment = null;
        $geoIndexed = false;
        $audit = null;

        $acquired = $this->lock->run($idempotencyKey, function () use ($payload, $idempotencyKey, &$enrichment, &$geoIndexed, &$audit): void {
            $cnpj = (string) ($payload['cnpj'] ?? data_get($payload, 'received.cnpj', ''));
            $tenantId = (string) ($payload['tenant_id'] ?? '');

            $enrichment = $this->enrichment->enrichByCnpj($cnpj);
            $geoIndexed = $this->geo->maybeIndex($idempotencyKey, $payload['received'] ?? $payload);

            $enriched = [
                'tenant_id' => $tenantId,
                'received' => $payload['received'] ?? $payload,
                'enrichment' => $enrichment,
            ];

            if ($tenantId !== '') {
                $audit = $this->dispatch->dispatchAndAudit($tenantId, $enriched);
            }
        });

        if (! $acquired) {
            return ['status' => 'busy'];
        }

        $result = [
            'status' => 'processed',
            'enrichment' => $enrichment ?? [],
            'geo_indexed' => $geoIndexed,
        ];

        if ($audit !== null) {
            $result['audit'] = $audit;
        }

        return $result;
    }
}
