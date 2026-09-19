<?php

namespace App\Http\Middleware;

use App\Repositories\TenantRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenantApiKey
{
    public function __construct(private TenantRepository $tenants) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('eventflow.auth.header', 'X-Api-Key');
        $apiKey = $request->headers->get($header);

        if (! is_string($apiKey) || $apiKey === '') {
            return response()->json(['message' => 'Missing API key.'], 401);
        }

        $tenant = $this->tenants->findByApiKey($apiKey);

        if ($tenant === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
