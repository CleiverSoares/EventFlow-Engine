<?php

namespace App\Contracts;

use App\Models\Tenant;

interface TenantRateLimiter
{
    /**
     * Attempt to consume one request for the tenant plan window.
     * Returns false when the plan ceiling was exceeded.
     */
    public function attempt(Tenant $tenant): bool;
}
