<?php

namespace App\Http\Controllers\Api;

use App\Enums\ExportReport;
use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreExportRequest;
use App\Models\Tenant;
use App\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(private ExportService $exports) {}

    public function store(StoreExportRequest $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey)) {
            $idempotencyKey = trim($idempotencyKey) ?: null;
        } else {
            $idempotencyKey = null;
        }

        $result = $this->exports->request(
            $tenant,
            ExportReport::from($request->validated('report')),
            $request->validated('filters') ?? [],
            $idempotencyKey,
            (bool) $request->boolean('sync'),
        );

        $export = $result['export'];
        $status = $export->status === ExportStatus::Completed ? 200 : 202;

        return response()->json([
            'ok' => true,
            'export_id' => $export->id,
            'status' => $export->status->value,
            'report' => $export->report->value,
            'idempotent_replay' => $result['replay'],
            'row_count' => $export->row_count,
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(
            $this->exports->listForTenant($tenant->id, (int) $request->query('per_page', 20)),
        );
    }

    public function show(Request $request, string $export): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $model = $this->exports->findForTenant($tenant->id, $export);
        if ($model === null) {
            return response()->json(['message' => 'Export not found.'], 404);
        }

        $waitMs = null;
        if ($model->started_at !== null) {
            $waitMs = $model->created_at->diffInMilliseconds($model->started_at);
        }

        return response()->json([
            'data' => $model,
            'wait_ms' => $waitMs,
        ]);
    }

    public function download(Request $request, string $export): StreamedResponse|JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $model = $this->exports->findForTenant($tenant->id, $export);
        if ($model === null) {
            return response()->json(['message' => 'Export not found.'], 404);
        }

        if ($model->status !== ExportStatus::Completed || $model->file_path === null) {
            return response()->json([
                'message' => 'Export is not ready for download.',
                'status' => $model->status->value,
            ], 409);
        }

        if (! Storage::disk('local')->exists($model->file_path)) {
            return response()->json(['message' => 'Export file missing.'], 410);
        }

        return Storage::disk('local')->download(
            $model->file_path,
            $model->id.'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }
}
