<?php

namespace App\Providers;

use App\Contracts\EnrichmentClient;
use App\Contracts\MessagePublisher;
use App\Contracts\TenantRateLimiter;
use App\Contracts\WebhookDispatcher;
use App\Observability\InMemoryMetricsRegistry;
use App\Observability\Tracing;
use App\Services\Dispatch\HttpWebhookDispatcher;
use App\Services\Enrichment\BrasilApiEnrichmentClient;
use App\Services\Messaging\RabbitMqMessagePublisher;
use App\Services\RateLimiting\CacheTenantRateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InMemoryMetricsRegistry::class);
        $this->app->singleton(Tracing::class);

        $this->app->bind(EnrichmentClient::class, BrasilApiEnrichmentClient::class);
        $this->app->bind(WebhookDispatcher::class, HttpWebhookDispatcher::class);
        $this->app->bind(TenantRateLimiter::class, CacheTenantRateLimiter::class);
        $this->app->singleton(MessagePublisher::class, RabbitMqMessagePublisher::class);
    }

    public function boot(): void
    {
        //
    }
}
