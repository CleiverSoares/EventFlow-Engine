<?php

namespace App\Services;

use App\Services\Locking\LeadProcessingLock;

/**
 * Phase 2 worker entry: acquire distributed lock before enrichment/dispatch work.
 */
class LeadProcessingPipeline
{
    public function __construct(
        private LeadProcessingLock $lock,
        private LeadEnrichmentService $enrichment,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     status: 'processed'|'busy',
     *     enrichment?: array<string, mixed>
     * }
     */
    public function process(string $idempotencyKey, array $payload): array
    {
        $enrichment = null;

        $acquired = $this->lock->run($idempotencyKey, function () use ($payload, &$enrichment): void {
            $enrichment = $this->enrichment->enrichByCnpj((string) ($payload['cnpj'] ?? ''));
        });

        if (! $acquired) {
            return ['status' => 'busy'];
        }

        return [
            'status' => 'processed',
            'enrichment' => $enrichment ?? [],
        ];
    }
}
