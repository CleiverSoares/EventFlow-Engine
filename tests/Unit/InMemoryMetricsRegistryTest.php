<?php

namespace Tests\Unit;

use App\Observability\InMemoryMetricsRegistry;
use Tests\TestCase;

class InMemoryMetricsRegistryTest extends TestCase
{
    public function test_records_counter_and_histogram_in_prometheus_text(): void
    {
        $registry = new InMemoryMetricsRegistry;

        $registry->incrementCounter('http_requests_total', [
            'method' => 'POST',
            'route' => 'api/leads',
            'status' => '201',
        ]);

        $registry->observeHistogram('http_request_duration_seconds', 0.12, [
            'method' => 'POST',
            'route' => 'api/leads',
        ]);

        $output = $registry->renderPrometheus();

        $this->assertStringContainsString('# TYPE http_requests_total counter', $output);
        $this->assertStringContainsString('http_requests_total{method="POST",route="api/leads",status="201"} 1', $output);
        $this->assertStringContainsString('# TYPE http_request_duration_seconds histogram', $output);
        $this->assertStringContainsString('http_request_duration_seconds_count{method="POST",route="api/leads"} 1', $output);
        $this->assertStringContainsString('http_request_duration_seconds_sum{method="POST",route="api/leads"} 0.12', $output);
        $this->assertStringContainsString('le="0.25"', $output);
    }
}
