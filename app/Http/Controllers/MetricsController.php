<?php

namespace App\Http\Controllers;

use App\Observability\InMemoryMetricsRegistry;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __construct(private InMemoryMetricsRegistry $metrics) {}

    public function __invoke(): Response
    {
        return response($this->metrics->renderPrometheus(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
