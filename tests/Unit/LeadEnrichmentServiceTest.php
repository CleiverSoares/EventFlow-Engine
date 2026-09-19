<?php

namespace Tests\Unit;

use App\Contracts\EnrichmentClient;
use App\Services\Enrichment\CacheAsideEnrichmentStore;
use App\Services\LeadEnrichmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LeadEnrichmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eventflow.redis.cache_prefix' => 'eventflow:enrichment:test:',
            'eventflow.redis.enrichment_ttl_seconds' => 3600,
            'eventflow.enrichment.base_url' => 'https://brasilapi.com.br/api',
            'eventflow.enrichment.timeout_seconds' => 5,
        ]);

        Cache::flush();
    }

    public function test_cache_hit_skips_http_call(): void
    {
        Http::preventStrayRequests();

        $cnpj = '12345678000199';
        $cached = [
            'cnpj' => $cnpj,
            'razao_social' => 'ACME CACHED',
            'nome_fantasia' => null,
            'uf' => 'SP',
            'municipio' => 'Sao Paulo',
        ];

        $store = new CacheAsideEnrichmentStore;
        $store->put($cnpj, $cached);

        $client = Mockery::mock(EnrichmentClient::class);
        $client->shouldNotReceive('enrichByCnpj');

        $service = new LeadEnrichmentService($client, $store);
        $result = $service->enrichByCnpj($cnpj);

        $this->assertSame($cached, $result);
        Http::assertNothingSent();
    }

    public function test_cache_miss_fetches_once_and_stores(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/12345678000199' => Http::response([
                'razao_social' => 'ACME LTDA',
                'nome_fantasia' => 'Acme',
                'uf' => 'RJ',
                'municipio' => 'Rio de Janeiro',
                'cnae_fiscal' => 6201501,
            ], 200),
        ]);

        $client = $this->app->make(EnrichmentClient::class);
        $store = new CacheAsideEnrichmentStore;
        $service = new LeadEnrichmentService($client, $store);

        $first = $service->enrichByCnpj('12.345.678/0001-99');
        $second = $service->enrichByCnpj('12345678000199');

        $expected = [
            'cnpj' => '12345678000199',
            'razao_social' => 'ACME LTDA',
            'nome_fantasia' => 'Acme',
            'uf' => 'RJ',
            'municipio' => 'Rio de Janeiro',
        ];

        $this->assertSame($expected, $first);
        $this->assertSame($expected, $second);
        $this->assertSame($expected, $store->get('12345678000199'));
        Http::assertSentCount(1);
    }

    public function test_http_failure_bubbles_without_caching(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response(['message' => 'not found'], 404),
        ]);

        $client = $this->app->make(EnrichmentClient::class);
        $store = new CacheAsideEnrichmentStore;
        $service = new LeadEnrichmentService($client, $store);

        try {
            $service->enrichByCnpj('00000000000000');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 404', $exception->getMessage());
        }

        $this->assertNull($store->get('00000000000000'));
        Http::assertSentCount(1);
    }
}
