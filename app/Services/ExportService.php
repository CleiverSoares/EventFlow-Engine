<?php

namespace App\Services;

use App\Contracts\ExportWakePublisher;
use App\Enums\ExportReport;
use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\Tenant;
use App\Observability\InMemoryMetricsRegistry;
use App\Repositories\ExportRepository;
use App\Repositories\TenantRepository;
use App\Services\Exports\CommercialDossierExporter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExportService
{
    public function __construct(
        private ExportRepository $exports,
        private CommercialDossierExporter $dossierExporter,
        private TenantRepository $tenants,
        private InMemoryMetricsRegistry $metrics,
        private ExportWakePublisher $wake,
    ) {}

    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{export: Export, replay: bool}
     */
    public function request(
        Tenant $tenant,
        ExportReport $report,
        array $filters = [],
        ?string $idempotencyKey = null,
        bool $sync = false,
    ): array {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->exports->findByTenantAndIdempotencyKey($tenant->id, $idempotencyKey);
            if ($existing !== null) {
                return ['export' => $existing, 'replay' => true];
            }
        }

        $export = $this->exports->createPending(
            $tenant->id,
            $report->value,
            $filters,
            $idempotencyKey !== '' ? $idempotencyKey : null,
        );

        $this->metrics->incrementCounter('eventflow_exports_accepted_total', [
            'plan' => $tenant->plan->value,
            'report' => $report->value,
        ]);

        $runInline = $sync || (string) config('eventflow.exports.mode', 'async') === 'sync';

        if ($runInline) {
            $this->process($export);
            $export = $export->refresh();
        } else {
            $this->wakeWorkers($export);
        }

        return ['export' => $export, 'replay' => false];
    }

    public function processNextFair(): ?Export
    {
        $export = $this->exports->claimNextFair();
        if ($export === null) {
            return null;
        }

        return $this->process($export);
    }

    public function process(Export $export): Export
    {
        if ($export->status === ExportStatus::Pending) {
            $export = $this->exports->markProcessing($export);
        }

        $tenant = $this->tenants->findById($export->tenant_id);
        $plan = $tenant?->plan->value ?? 'unknown';

        if ($export->started_at !== null && $export->created_at !== null) {
            $waitSeconds = max(0.0, (float) $export->created_at->diffInMilliseconds($export->started_at) / 1000);
            $this->metrics->observeHistogram('eventflow_export_queue_wait_seconds', $waitSeconds, [
                'plan' => $plan,
            ]);
        }

        $started = hrtime(true);

        try {
            $result = match ($export->report) {
                ExportReport::CommercialDossier => $this->dossierExporter->export(
                    $export,
                    is_array($export->filters) ? $export->filters : [],
                ),
            };

            $completed = $this->exports->markCompleted($export, $result['path'], $result['rows']);
            $this->recordProcessOutcome($plan, 'completed', $started);

            return $completed;
        } catch (Throwable $exception) {
            $failed = $this->exports->markFailed($export, $exception->getMessage());
            $this->recordProcessOutcome($plan, 'failed', $started);

            return $failed;
        }
    }

    public function listForTenant(string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->exports->paginateForTenant($tenantId, $perPage);
    }

    public function findForTenant(string $tenantId, string $id): ?Export
    {
        return $this->exports->findForTenant($tenantId, $id);
    }

    private function wakeWorkers(Export $export): void
    {
        try {
            $this->wake->publishWake([
                'type' => 'export.wake',
                'export_id' => $export->id,
                'tenant_id' => $export->tenant_id,
            ]);
            $this->metrics->incrementCounter('eventflow_exports_wake_published_total', ['result' => 'ok']);
        } catch (Throwable $exception) {
            Log::warning('Export wake publish failed; worker idle-poll will catch up.', [
                'export_id' => $export->id,
                'error' => $exception->getMessage(),
            ]);
            $this->metrics->incrementCounter('eventflow_exports_wake_published_total', ['result' => 'failed']);
        }
    }

    private function recordProcessOutcome(string $plan, string $result, int|float $startedAtNs): void
    {
        $durationSeconds = (hrtime(true) - $startedAtNs) / 1_000_000_000;

        $this->metrics->incrementCounter('eventflow_exports_processed_total', [
            'plan' => $plan,
            'result' => $result,
        ]);
        $this->metrics->observeHistogram('eventflow_export_process_duration_seconds', $durationSeconds, [
            'plan' => $plan,
            'result' => $result,
        ]);
    }
}
