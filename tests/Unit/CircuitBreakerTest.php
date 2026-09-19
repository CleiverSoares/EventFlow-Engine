<?php

namespace Tests\Unit;

use App\Enums\CircuitState;
use App\Exceptions\CircuitOpenException;
use App\Services\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'eventflow.circuit_breaker.failure_threshold' => 2,
            'eventflow.circuit_breaker.recovery_timeout_seconds' => 1,
        ]);
    }

    public function test_opens_after_threshold_and_rejects_while_open(): void
    {
        $breaker = new CircuitBreaker('webhook-test');

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(function (): void {
                    throw new RuntimeException('down');
                });
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(CircuitState::Open, $breaker->state());

        $this->expectException(CircuitOpenException::class);
        $breaker->call(fn (): true => true);
    }

    public function test_half_open_allows_trial_and_closes_on_success(): void
    {
        $breaker = new CircuitBreaker('webhook-half');

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(function (): void {
                    throw new RuntimeException('down');
                });
            } catch (RuntimeException) {
                // expected
            }
        }

        Cache::put('eventflow:cb:webhook-half:opened_at', time() - 5);

        $result = $breaker->call(fn (): string => 'ok');

        $this->assertSame('ok', $result);
        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    public function test_half_open_failure_reopens(): void
    {
        $breaker = new CircuitBreaker('webhook-reopen');

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(function (): void {
                    throw new RuntimeException('down');
                });
            } catch (RuntimeException) {
                // expected
            }
        }

        Cache::put('eventflow:cb:webhook-reopen:opened_at', time() - 5);

        try {
            $breaker->call(function (): void {
                throw new RuntimeException('still down');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(CircuitState::Open, $breaker->state());
        $this->assertFalse($breaker->allowsRequest());
    }
}
