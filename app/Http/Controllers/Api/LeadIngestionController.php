<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLeadRequest;
use App\Models\Tenant;
use App\Services\LeadIngestionService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class LeadIngestionController extends Controller
{
    public function __construct(private LeadIngestionService $ingestion) {}

    public function store(StoreLeadRequest $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        try {
            $result = $this->ingestion->ingest($tenant, $request->validated());
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'phase1',
            'audit_id' => $result['audit']->id,
            'status' => $result['audit']->status->value,
        ], 201);
    }
}
