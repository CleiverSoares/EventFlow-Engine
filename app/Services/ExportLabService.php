<?php

namespace App\Services;

use App\Repositories\ExportRepository;

class ExportLabService
{
    public function __construct(private ExportRepository $exports) {}

    /**
     * One-shot snapshot for the live lab board (WebSocket pushes deltas after this).
     *
     * @return array{
     *     totals: array{pending: int, processing: int, completed: int, failed: int},
     *     tenants: list<array{tenant_id: string, name: string, plan: string, api_key: string, pending: int, processing: int, completed: int, failed: int}>,
     *     recent: list<array<string, mixed>>,
     *     generated_at: string
     * }
     */
    public function snapshot(int $recentLimit = 50): array
    {
        $totals = $this->exports->countByStatus();
        $breakdown = $this->exports->statusBreakdownByTenant();

        /** @var array<string, array{tenant_id: string, name: string, plan: string, api_key: string, pending: int, processing: int, completed: int, failed: int}> $tenants */
        $tenants = [];

        foreach ($breakdown as $row) {
            $id = (string) $row->tenant_id;
            if (! isset($tenants[$id])) {
                $tenants[$id] = [
                    'tenant_id' => $id,
                    'name' => (string) $row->name,
                    'plan' => (string) $row->plan,
                    'api_key' => (string) $row->api_key,
                    'pending' => 0,
                    'processing' => 0,
                    'completed' => 0,
                    'failed' => 0,
                ];
            }

            $status = (string) $row->status;
            if (array_key_exists($status, $tenants[$id]) && is_int($tenants[$id][$status])) {
                $tenants[$id][$status] = (int) $row->aggregate;
            }
        }

        $recent = $this->exports->recentForLab($recentLimit)->map(function ($export): array {
            $waitMs = null;
            if ($export->started_at !== null && $export->created_at !== null) {
                $waitMs = (int) $export->created_at->diffInMilliseconds($export->started_at);
            }

            return [
                'id' => $export->id,
                'tenant_id' => $export->tenant_id,
                'tenant_name' => $export->tenant?->name,
                'plan' => $export->tenant?->plan?->value ?? $export->tenant?->plan,
                'api_key' => $export->tenant?->api_key,
                'report' => $export->report->value,
                'status' => $export->status->value,
                'row_count' => $export->row_count,
                'wait_ms' => $waitMs,
                'created_at' => $export->created_at?->toIso8601String(),
                'started_at' => $export->started_at?->toIso8601String(),
                'finished_at' => $export->finished_at?->toIso8601String(),
                'error_message' => $export->error_message,
            ];
        })->values()->all();

        return [
            'totals' => $totals,
            'tenants' => array_values($tenants),
            'recent' => $recent,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
