<?php

namespace App\Repositories;

use App\Enums\TenantPlan;
use App\Models\Tenant;

class TenantRepository
{
    public function findByApiKey(string $apiKey): ?Tenant
    {
        return Tenant::query()->where('api_key', $apiKey)->first();
    }

    public function findById(string $id): ?Tenant
    {
        return Tenant::query()->whereKey($id)->first();
    }

    /**
     * @param  array{name: string, api_key: string, plan: TenantPlan|string}  $attributes
     */
    public function create(array $attributes): Tenant
    {
        return Tenant::query()->create($attributes);
    }
}
