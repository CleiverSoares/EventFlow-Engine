<?php

namespace Database\Factories;

use App\Enums\TenantPlan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'api_key' => 'ef_'.Str::random(40),
            'plan' => fake()->randomElement(TenantPlan::cases()),
        ];
    }

    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => TenantPlan::Basic,
        ]);
    }

    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => TenantPlan::Pro,
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => TenantPlan::Enterprise,
        ]);
    }
}
