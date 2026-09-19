<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\PipelineQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ValueError;

class OutboxEventController extends Controller
{
    public function __construct(private PipelineQueryService $pipeline) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        try {
            $page = $this->pipeline->listOutbox(
                $tenant->id,
                $request->query('status'),
                (int) $request->query('per_page', 20),
            );
        } catch (ValueError) {
            return response()->json(['message' => 'Invalid outbox status.'], 422);
        }

        return response()->json($page);
    }

    public function show(Request $request, string $outbox): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $event = $this->pipeline->getOutbox($tenant->id, $outbox);
        if ($event === null) {
            return response()->json(['message' => 'Outbox event not found.'], 404);
        }

        return response()->json(['data' => $event]);
    }
}
