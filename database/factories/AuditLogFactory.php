<?php

namespace Database\Factories;

use App\Enums\AuditStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_payload' => [
                'cnpj' => fake()->numerify('##############'),
                'enriched' => true,
            ],
            'status' => AuditStatus::Success,
        ];
    }
}
