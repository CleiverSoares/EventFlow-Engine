<?php

namespace App\Services\Enrichment;

use App\Contracts\EnrichmentClient;
use App\Services\Resilience\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrasilApiEnrichmentClient implements EnrichmentClient
{
    public function enrichByCnpj(string $cnpj): array
    {
        return (new CircuitBreaker('enrichment'))->call(function () use ($cnpj): array {
            $cnpj = preg_replace('/\D+/', '', $cnpj) ?? '';
            $baseUrl = rtrim((string) config('eventflow.enrichment.base_url'), '/');
            $timeout = (int) config('eventflow.enrichment.timeout_seconds', 5);
            $token = config('eventflow.enrichment.token');

            try {
                $request = Http::baseUrl($baseUrl)
                    ->acceptJson()
                    ->connectTimeout(min(3, $timeout))
                    ->timeout($timeout);

                if (is_string($token) && $token !== '') {
                    $request = $request->withToken($token);
                }

                $response = $request->get('/cnpj/v1/'.$cnpj);
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
            ];
        });
    }
}
