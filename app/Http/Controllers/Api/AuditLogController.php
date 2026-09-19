<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\PipelineQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ValueError;

class AuditLogController extends Controller
{
    public function __construct(private PipelineQueryService $pipeline) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        try {
            $page = $this->pipeline->listAuditLogs(
                $tenant->id,
                $request->query('status'),
                (int) $request->query('per_page', 20),
            );
        } catch (ValueError) {
            return response()->json(['message' => 'Invalid audit status.'], 422);
        }

        return response()->json($page);
    }

    public function show(Request $request, string $auditLog): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $log = $this->pipeline->getAuditLog($tenant->id, $auditLog);
        if ($log === null) {
            return response()->json(['message' => 'Audit log not found.'], 404);
        }

        return response()->json(['data' => $log]);
    }
}
