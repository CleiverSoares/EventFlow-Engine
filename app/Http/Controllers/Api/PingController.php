<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'ok' => true,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan->value,
            ],
        ]);
    }
}
