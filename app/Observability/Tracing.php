<?php

namespace App\Observability;

use Illuminate\Support\Facades\Log;

class Tracing
{
    private static ?TraceContext $current = null;

    public function __construct(private TraceSpanExporter $exporter) {}

    public function current(): ?TraceContext
    {
        return self::$current;
    }

    public function setCurrent(?TraceContext $context): void
    {
        self::$current = $context;

        if ($context !== null) {
            Log::withContext([
                'trace_id' => $context->traceId,
                'span_id' => $context->spanId,
            ]);
        }
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     * @param  callable(TraceContext): mixed  $callback
     */
    public function run(string $name, ?TraceContext $parent, callable $callback, array $attributes = []): mixed
    {
        $context = $parent?->child() ?? TraceContext::generate();
        $previous = self::$current;
        $this->setCurrent($context);

        $start = $this->nowNanos();

        try {
            return $callback($context);
        } finally {
            $this->exporter->export($name, $context, $start, $this->nowNanos(), $attributes);
            $this->setCurrent($previous);
        }
    }

    public function withTenant(?string $tenantId): void
    {
        if ($tenantId !== null && $tenantId !== '') {
            Log::withContext(['tenant_id' => $tenantId]);
        }
    }

    private function nowNanos(): string
    {
        return sprintf('%.0f', (float) hrtime(true));
    }
}
