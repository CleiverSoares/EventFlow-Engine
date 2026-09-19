<?php

namespace Database\Factories;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'aggregate_type' => 'lead.incoming',
            'tenant_id' => Tenant::factory(),
            'payload' => [
                'cnpj' => fake()->numerify('##############'),
                'name' => fake()->company(),
                'lat' => fake()->latitude(),
                'lng' => fake()->longitude(),
            ],
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'idempotency_key' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OutboxStatus::Pending,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OutboxStatus::Processed,
        ]);
    }
}
