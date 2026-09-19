<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMetricsToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('eventflow.metrics.token', '');

        if ($expected === '') {
            return response('Metrics token is not configured.', 503);
        }

        $provided = $request->bearerToken()
            ?? $request->header('X-Metrics-Token')
            ?? $request->query('token');

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
