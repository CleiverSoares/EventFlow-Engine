<?php

namespace Tests\Unit;

use App\Contracts\ExportWakePublisher;
use App\Enums\ExportReport;
use App\Enums\ExportStatus;
use App\Enums\TenantPlan;
use App\Models\Export;
use App\Models\Tenant;
use App\Observability\InMemoryMetricsRegistry;
use App\Repositories\ExportRepository;
use App\Services\ExportService;
use App\Services\Messaging\RabbitMqExportConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExportWakeFairnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_async_request_publishes_rabbit_wake(): void
    {
        $tenant = Tenant::factory()->create(['plan' => TenantPlan::Pro]);

        $wake = Mockery::mock(ExportWakePublisher::class);
        $wake->shouldReceive('publishWake')
            ->once()
            ->withArgs(function (array $body): bool {
                return ($body['type'] ?? null) === 'export.wake';
            });

        $this->app->instance(ExportWakePublisher::class, $wake);
        config([
            'eventflow.exports.mode' => 'async',
            'eventflow.exports.wake_coalesce_ms' => 0,
        ]);

        $result = app(ExportService::class)->request(
            $tenant,
            ExportReport::CommercialDossier,
        );

        $this->assertFalse($result['replay']);
        $this->assertSame(ExportStatus::Pending, $result['export']->status);
    }

    public function test_async_request_coalesces_wake_within_window(): void
    {
        $tenant = Tenant::factory()->create(['plan' => TenantPlan::Pro]);

        $wake = Mockery::mock(ExportWakePublisher::class);
        $wake->shouldReceive('publishWake')->once();

        $this->app->instance(ExportWakePublisher::class, $wake);
        config([
            'eventflow.exports.mode' => 'async',
            'eventflow.exports.wake_coalesce_ms' => 5_000,
            'cache.default' => 'array',
        ]);

        app(ExportService::class)->request($tenant, ExportReport::CommercialDossier);
        app(ExportService::class)->request($tenant, ExportReport::CommercialDossier);
    }

    public function test_handle_wake_drains_with_fair_claim_not_fifo_id(): void
    {
        config([
            'eventflow.exports.max_processing_global' => 4,
            'eventflow.exports.max_processing_enterprise' => 1,
            'eventflow.exports.max_processing_basic' => 1,
        ]);

        $whale = Tenant::factory()->create(['plan' => TenantPlan::Enterprise]);
        $light = Tenant::factory()->create(['plan' => TenantPlan::Basic]);

        Export::factory()->create([
            'tenant_id' => $whale->id,
            'status' => ExportStatus::Processing,
        ]);

        $lightPending = Export::factory()->create([
            'tenant_id' => $light->id,
            'status' => ExportStatus::Pending,
            'created_at' => now()->subMinute(),
        ]);

        Export::factory()->create([
            'tenant_id' => $whale->id,
            'status' => ExportStatus::Pending,
            'created_at' => now()->subMinutes(10),
        ]);

        $wake = Mockery::mock(ExportWakePublisher::class);
        $wake->shouldReceive('publishWake')->once();
        $wake->shouldReceive('publishDelayedWake')->never();
        $this->app->instance(ExportWakePublisher::class, $wake);

        config([
            'cache.default' => 'array',
            'eventflow.exports.wake_coalesce_ms' => 250,
        ]);

        $exports = Mockery::mock(ExportService::class);
        $exports->shouldReceive('processNextFair')
            ->once()
            ->andReturn($lightPending->fresh());
        $exports->shouldReceive('processNextFair')
            ->once()
            ->andReturn(null);

        $consumer = new RabbitMqExportConsumer(
            $exports,
            app(ExportRepository::class),
            $wake,
            app(InMemoryMetricsRegistry::class),
        );

        $result = $consumer->handleWake();

        $this->assertSame(1, $result['processed']);
        $this->assertFalse($result['delayed']);
    }
}
