<?php

namespace App\Services\Enrichment;

use App\Contracts\EnrichmentClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrasilApiEnrichmentClient implements EnrichmentClient
{
    public function enrichByCnpj(string $cnpj): array
    {
        $cnpj = preg_replace('/\D+/', '', $cnpj) ?? '';
        $baseUrl = rtrim((string) config('eventflow.enrichment.base_url'), '/');
        $timeout = (int) config('eventflow.enrichment.timeout_seconds', 5);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->get('/cnpj/v1/'.$cnpj);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Enrichment provider unreachable.', previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Enrichment provider returned HTTP '.$response->status());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        return [
            'cnpj' => $cnpj,
            'razao_social' => $data['razao_social'] ?? null,
            'nome_fantasia' => $data['nome_fantasia'] ?? null,
            'uf' => $data['uf'] ?? null,
            'municipio' => $data['municipio'] ?? null,
            'raw' => $data,
        ];
    }
}
