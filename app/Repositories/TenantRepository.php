<?php

namespace App\Repositories;

use App\Models\Tenant;

class TenantRepository
{
    public function findByApiKey(string $apiKey): ?Tenant
    {
        return Tenant::query()->where('api_key', $apiKey)->first();
    }

    /**
     * @param  array{name: string, api_key: string, plan: \App\Enums\TenantPlan|string}  $attributes
     */
    public function create(array $attributes): Tenant
    {
        return Tenant::query()->create($attributes);
    }
}
