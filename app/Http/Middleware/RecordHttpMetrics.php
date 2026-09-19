<?php

namespace App\Http\Middleware;

use App\Observability\InMemoryMetricsRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordHttpMetrics
{
    public function __construct(private InMemoryMetricsRegistry $metrics) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record($request, 500, $startedAt);
            throw $exception;
        }

        $this->record($request, $response->getStatusCode(), $startedAt);

        return $response;
    }

    private function record(Request $request, int $status, int|float $startedAtNs): void
    {
        $route = $request->path();
        $labels = [
            'method' => $request->method(),
            'route' => $route,
            'status' => (string) $status,
        ];

        $durationSeconds = (hrtime(true) - $startedAtNs) / 1_000_000_000;

        $this->metrics->observeHistogram('http_request_duration_seconds', $durationSeconds, [
            'method' => $labels['method'],
            'route' => $labels['route'],
        ]);

        $this->metrics->incrementCounter('http_requests_total', $labels);

        if ($status >= 500) {
            $this->metrics->incrementCounter('http_request_errors_total', [
                'method' => $labels['method'],
                'route' => $labels['route'],
            ]);
        }
    }
}
