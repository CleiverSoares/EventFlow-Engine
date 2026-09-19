<?php

namespace App\Services\Exports;

use App\Models\Export;
use App\Repositories\CommercialDossierRepository;
use Illuminate\Support\Facades\Storage;

class CommercialDossierExporter
{
    public function __construct(private CommercialDossierRepository $dossier) {}

    /**
     * Stream a multi-table commercial dossier CSV for one tenant (chunked keyset by deal id).
     *
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{path: string, rows: int}
     */
    public function export(Export $export, array $filters = []): array
    {
        $tenantId = $export->tenant_id;
        $chunkSize = (int) config('eventflow.exports.chunk_size', 200);
        $relativeDir = 'exports/'.$tenantId;
        $relativePath = $relativeDir.'/'.$export->id.'.csv';

        Storage::disk('local')->makeDirectory($relativeDir);
        $absolute = Storage::disk('local')->path($relativePath);

        $handle = fopen($absolute, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open export file for writing.');
        }

        try {
            fputcsv($handle, [
                'deal_code', 'deal_status', 'opened_at', 'closed_at',
                'account_document', 'account_name',
                'sku', 'quantity', 'unit_price',
                'invoice_number', 'invoice_due_date', 'invoice_total',
                'payment_status', 'payment_amount', 'paid_at',
                'activity_count',
            ]);

            $rows = 0;
            $afterId = null;

            while (true) {
                $deals = $this->dossier->dealsChunk($tenantId, $afterId, $chunkSize, $filters);
                if ($deals->isEmpty()) {
                    break;
                }

                $dealIds = $deals->pluck('id')->all();
                $accountIds = $deals->pluck('account_id')->unique()->values()->all();

                $accounts = $this->dossier->accountsByIds($tenantId, $accountIds);
                $items = $this->dossier->dealItemsGrouped($tenantId, $dealIds);
                $activities = $this->dossier->activityCountsByDeal($tenantId, $dealIds);
                $invoices = $this->dossier->invoicesGroupedByDeal($tenantId, $dealIds);
                $invoiceIds = $invoices->flatten(1)->pluck('id')->all();
                $payments = $this->dossier->paymentsGroupedByInvoice($tenantId, $invoiceIds);

                foreach ($deals as $deal) {
                    $account = $accounts->get($deal->account_id);
                    $dealItems = $items->get($deal->id, collect());
                    $dealInvoices = $invoices->get($deal->id, collect());
                    $activityCount = (int) ($activities[$deal->id] ?? 0);

                    if ($dealItems->isEmpty()) {
                        $dealItems = collect([(object) [
                            'sku' => '',
                            'quantity' => 0,
                            'unit_price' => 0,
                        ]]);
                    }

                    foreach ($dealItems as $item) {
                        if ($dealInvoices->isEmpty()) {
                            fputcsv($handle, [
                                $deal->code,
                                $deal->status,
                                $deal->opened_at,
                                $deal->closed_at,
                                $account->document ?? '',
                                $account->name ?? '',
                                $item->sku,
                                $item->quantity,
                                $item->unit_price,
                                '',
                                '',
                                '',
                                '',
                                '',
                                '',
                                $activityCount,
                            ]);
                            $rows++;

                            continue;
                        }

                        foreach ($dealInvoices as $invoice) {
                            $invoicePayments = $payments->get($invoice->id, collect());
                            if ($invoicePayments->isEmpty()) {
                                $invoicePayments = collect([(object) [
                                    'status' => '',
                                    'amount' => '',
                                    'paid_at' => '',
                                ]]);
                            }

                            foreach ($invoicePayments as $payment) {
                                fputcsv($handle, [
                                    $deal->code,
                                    $deal->status,
                                    $deal->opened_at,
                                    $deal->closed_at,
                                    $account->document ?? '',
                                    $account->name ?? '',
                                    $item->sku,
                                    $item->quantity,
                                    $item->unit_price,
                                    $invoice->number,
                                    $invoice->due_date,
                                    $invoice->total,
                                    $payment->status,
                                    $payment->amount,
                                    $payment->paid_at,
                                    $activityCount,
                                ]);
                                $rows++;
                            }
                        }
                    }

                    $afterId = $deal->id;
                }

                if ($deals->count() < $chunkSize) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'path' => $relativePath,
            'rows' => $rows,
        ];
    }
}
