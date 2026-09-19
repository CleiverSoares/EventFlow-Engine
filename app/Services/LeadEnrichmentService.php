<?php

namespace App\Services;

use App\Contracts\EnrichmentClient;
use App\Services\Enrichment\CacheAsideEnrichmentStore;

class LeadEnrichmentService
{
    public function __construct(
        private EnrichmentClient $enrichmentClient,
        private CacheAsideEnrichmentStore $store,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function enrichByCnpj(string $cnpj): array
    {
        $cnpj = preg_replace('/\D+/', '', $cnpj) ?? '';

        $cached = $this->store->get($cnpj);
        if ($cached !== null) {
            return $cached;
        }

        $enriched = $this->enrichmentClient->enrichByCnpj($cnpj);
        $this->store->put($cnpj, $enriched);

        return $enriched;
    }
}
