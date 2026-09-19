<?php

namespace App\Services;

use App\Services\Geo\LeadGeoIndex;
use App\Services\Locking\LeadProcessingLock;

/**
 * Phase 2 worker entry: acquire distributed lock before enrichment/dispatch work.
 */
class LeadProcessingPipeline
{
    public function __construct(
        private LeadProcessingLock $lock,
        private LeadEnrichmentService $enrichment,
        private LeadGeoIndex $geo,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     status: 'processed'|'busy',
     *     enrichment?: array<string, mixed>,
     *     geo_indexed?: bool
     * }
     */
    public function process(string $idempotencyKey, array $payload): array
    {
        $enrichment = null;
        $geoIndexed = false;

        $acquired = $this->lock->run($idempotencyKey, function () use ($payload, $idempotencyKey, &$enrichment, &$geoIndexed): void {
            $enrichment = $this->enrichment->enrichByCnpj((string) ($payload['cnpj'] ?? ''));
            $geoIndexed = $this->geo->maybeIndex($idempotencyKey, $payload);
        });

        if (! $acquired) {
            return ['status' => 'busy'];
        }

        return [
            'status' => 'processed',
            'enrichment' => $enrichment ?? [],
            'geo_indexed' => $geoIndexed,
        ];
    }
}
