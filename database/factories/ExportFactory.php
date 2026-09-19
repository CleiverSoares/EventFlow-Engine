<?php

namespace Database\Factories;

use App\Enums\ExportReport;
use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'report' => ExportReport::CommercialDossier,
            'format' => 'csv',
            'status' => ExportStatus::Pending,
            'filters' => [],
            'idempotency_key' => null,
        ];
    }
}
