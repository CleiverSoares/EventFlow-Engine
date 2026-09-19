<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Cache;

class CacheAsideEnrichmentStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $cnpj): ?array
    {
        $value = Cache::get($this->key($cnpj));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(string $cnpj, array $payload): void
    {
        Cache::put(
            $this->key($cnpj),
            $payload,
            (int) config('eventflow.redis.enrichment_ttl_seconds', 86400),
        );
    }

    private function key(string $cnpj): string
    {
        return (string) config('eventflow.redis.cache_prefix', 'eventflow:enrichment:').$cnpj;
    }
}
