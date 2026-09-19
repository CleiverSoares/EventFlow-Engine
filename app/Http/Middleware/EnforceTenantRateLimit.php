<?php

namespace App\Http\Middleware;

use App\Contracts\TenantRateLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantRateLimit
{
    public function __construct(private TenantRateLimiter $rateLimiter) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('eventflow.mode') !== 'phase2') {
            return $next($request);
        }

        $tenant = $request->attributes->get('tenant');

        if ($tenant === null || ! $this->rateLimiter->attempt($tenant)) {
            return response()->json([
                'message' => 'Too Many Requests',
            ], 429);
        }

        return $next($request);
    }
}
