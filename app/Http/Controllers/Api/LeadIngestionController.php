<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OutboxBackpressureException;
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

        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey)) {
            $idempotencyKey = trim($idempotencyKey);
            if ($idempotencyKey === '') {
                $idempotencyKey = null;
            }
        } else {
            $idempotencyKey = null;
        }

        try {
            $result = $this->ingestion->ingest($tenant, $request->validated(), $idempotencyKey);
        } catch (OutboxBackpressureException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        if (($result['mode'] ?? null) === 'phase2') {
            return response()->json([
                'ok' => true,
                'mode' => 'phase2',
                'outbox_id' => $result['outbox']->id,
                'status' => $result['outbox']->status->value,
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
            ], 202);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'phase1',
            'audit_id' => $result['audit']->id,
            'status' => $result['audit']->status->value,
        ], 201);
    }
}
