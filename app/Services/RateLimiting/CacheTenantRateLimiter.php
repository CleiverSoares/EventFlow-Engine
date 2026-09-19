<?php

namespace App\Services\RateLimiting;

use App\Contracts\TenantRateLimiter;
use App\Models\Tenant;
use Illuminate\Support\Facades\RateLimiter;

class CacheTenantRateLimiter implements TenantRateLimiter
{
    public function attempt(Tenant $tenant): bool
    {
        $maxAttempts = (int) config('eventflow.rate_limits.'.$tenant->plan->value, 60);
        $key = config('eventflow.redis.rate_prefix', 'eventflow:rate:').$tenant->id;

        return RateLimiter::attempt(
            $key,
            $maxAttempts,
            fn (): bool => true,
            decaySeconds: 60,
        );
    }
}
