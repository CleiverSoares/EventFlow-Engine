<?php

namespace App\Observability;

final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly string $traceFlags = '01',
        public readonly ?string $parentSpanId = null,
    ) {}

    public static function generate(): self
    {
        return new self(
            traceId: bin2hex(random_bytes(16)),
            spanId: bin2hex(random_bytes(8)),
            traceFlags: '01',
        );
    }

    public function child(): self
    {
        return new self(
            traceId: $this->traceId,
            spanId: bin2hex(random_bytes(8)),
            traceFlags: $this->traceFlags,
            parentSpanId: $this->spanId,
        );
    }

    public function toTraceparent(): string
    {
        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $this->traceFlags);
    }

    public static function fromTraceparent(?string $header): ?self
    {
        if ($header === null || $header === '') {
            return null;
        }

        if (! preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', trim($header), $matches)) {
            return null;
        }

        return new self(
            traceId: strtolower($matches[1]),
            spanId: strtolower($matches[2]),
            traceFlags: strtolower($matches[3]),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toMessageHeaders(): array
    {
        $headers = [
            'traceparent' => $this->toTraceparent(),
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
        ];

        if ($this->parentSpanId !== null) {
            $headers['parent_span_id'] = $this->parentSpanId;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function fromMessageHeaders(array $headers): ?self
    {
        if (isset($headers['traceparent']) && is_string($headers['traceparent'])) {
            $fromParent = self::fromTraceparent($headers['traceparent']);
            if ($fromParent !== null) {
                return $fromParent;
            }
        }

        $traceId = isset($headers['trace_id']) ? (string) $headers['trace_id'] : '';
        $spanId = isset($headers['span_id']) ? (string) $headers['span_id'] : '';

        if (! preg_match('/^[0-9a-f]{32}$/i', $traceId) || ! preg_match('/^[0-9a-f]{16}$/i', $spanId)) {
            return null;
        }

        $parent = isset($headers['parent_span_id']) ? (string) $headers['parent_span_id'] : null;

        return new self(
            traceId: strtolower($traceId),
            spanId: strtolower($spanId),
            parentSpanId: $parent !== null && $parent !== '' ? strtolower($parent) : null,
        );
    }
}
