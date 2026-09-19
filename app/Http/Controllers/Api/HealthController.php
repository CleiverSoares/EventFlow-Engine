<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private HealthCheckService $health) {}

    public function __invoke(): JsonResponse
    {
        $result = $this->health->check();

        return response()->json($result, $result['ok'] ? 200 : 503);
    }
}
