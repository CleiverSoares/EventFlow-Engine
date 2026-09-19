<?php

namespace Tests\Unit;

use App\Observability\TraceContext;
use Tests\TestCase;

class TraceContextTest extends TestCase
{
    public function test_round_trips_w3c_traceparent_header(): void
    {
        $original = TraceContext::generate();
        $header = $original->toTraceparent();

        $parsed = TraceContext::fromTraceparent($header);

        $this->assertNotNull($parsed);
        $this->assertSame($original->traceId, $parsed->traceId);
        $this->assertSame($original->spanId, $parsed->spanId);
        $this->assertSame($original->traceFlags, $parsed->traceFlags);
    }

    public function test_injects_and_extracts_message_metadata(): void
    {
        $parent = TraceContext::generate();
        $child = $parent->child();

        $headers = $child->toMessageHeaders();

        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertSame($child->traceId, $headers['trace_id']);
        $this->assertSame($parent->spanId, $headers['parent_span_id']);

        $extracted = TraceContext::fromMessageHeaders($headers);

        $this->assertNotNull($extracted);
        $this->assertSame($child->traceId, $extracted->traceId);
        $this->assertSame($child->spanId, $extracted->spanId);
    }

    public function test_rejects_invalid_traceparent(): void
    {
        $this->assertNull(TraceContext::fromTraceparent('not-a-trace'));
        $this->assertNull(TraceContext::fromMessageHeaders(['trace_id' => 'short']));
    }
}
