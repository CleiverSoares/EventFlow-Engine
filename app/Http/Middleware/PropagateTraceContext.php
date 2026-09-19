<?php

namespace App\Http\Middleware;

use App\Observability\TraceContext;
use App\Observability\Tracing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PropagateTraceContext
{
    public function __construct(private Tracing $tracing) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $parent = TraceContext::fromTraceparent($request->headers->get('traceparent'));

        return $this->tracing->run(
            'http.request',
            $parent,
            function (TraceContext $span) use ($next, $request): Response {
                $response = $next($request);
                $response->headers->set('traceparent', $span->toTraceparent());
                $response->headers->set('X-Trace-Id', $span->traceId);

                return $response;
            },
            [
                'http.method' => $request->method(),
                'http.route' => $request->path(),
            ],
        );
    }
}
