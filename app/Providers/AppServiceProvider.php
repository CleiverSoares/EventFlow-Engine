<?php

namespace App\Providers;

use App\Contracts\EnrichmentClient;
use App\Contracts\TenantRateLimiter;
use App\Contracts\WebhookDispatcher;
use App\Observability\InMemoryMetricsRegistry;
use App\Services\Dispatch\HttpWebhookDispatcher;
use App\Services\Enrichment\BrasilApiEnrichmentClient;
use App\Services\RateLimiting\CacheTenantRateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InMemoryMetricsRegistry::class);

        $this->app->bind(EnrichmentClient::class, BrasilApiEnrichmentClient::class);
        $this->app->bind(WebhookDispatcher::class, HttpWebhookDispatcher::class);
        $this->app->bind(TenantRateLimiter::class, CacheTenantRateLimiter::class);
    }

    public function boot(): void
    {
        //
    }
}
