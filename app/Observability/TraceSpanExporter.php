<?php

namespace App\Observability;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TraceSpanExporter
{
    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    public function export(string $name, TraceContext $context, string $startUnixNano, string $endUnixNano, array $attributes = []): void
    {
        if (! (bool) config('eventflow.tracing.enabled', true)) {
            return;
        }

        $endpoint = (string) config('eventflow.tracing.otlp_endpoint');
        $service = (string) config('eventflow.tracing.service_name', 'eventflow-engine');

        $payload = [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [[
                        'key' => 'service.name',
                        'value' => ['stringValue' => $service],
                    ]],
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => 'eventflow'],
                    'spans' => [[
                        'traceId' => $context->traceId,
                        'spanId' => $context->spanId,
                        'parentSpanId' => $context->parentSpanId ?? '',
                        'name' => $name,
                        'kind' => 1,
                        'startTimeUnixNano' => $startUnixNano,
                        'endTimeUnixNano' => $endUnixNano,
                        'attributes' => $this->encodeAttributes($attributes),
                    ]],
                ]],
            ]],
        ];

        try {
            Http::acceptJson()
                ->timeout(2)
                ->connectTimeout(1)
                ->post($endpoint, $payload);
        } catch (Throwable $exception) {
            Log::debug('OTLP export failed', [
                'error' => $exception->getMessage(),
                'trace_id' => $context->traceId,
            ]);
        }
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function encodeAttributes(array $attributes): array
    {
        $encoded = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $encoded[] = [
                'key' => (string) $key,
                'value' => match (true) {
                    is_bool($value) => ['boolValue' => $value],
                    is_int($value) => ['intValue' => (string) $value],
                    is_float($value) => ['doubleValue' => $value],
                    default => ['stringValue' => (string) $value],
                },
            ];
        }

        return $encoded;
    }
}
