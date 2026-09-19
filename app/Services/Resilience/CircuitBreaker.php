<?php

namespace App\Services\Resilience;

use App\Enums\CircuitState;
use App\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CircuitBreaker
{
    public function __construct(private string $name) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        if (! $this->allowsRequest()) {
            throw new CircuitOpenException($this->name);
        }

        try {
            $result = $callback();
            $this->recordSuccess();

            return $result;
        } catch (Throwable $exception) {
            $this->recordFailure();

            throw $exception;
        }
    }

    public function state(): CircuitState
    {
        return CircuitState::tryFrom((string) Cache::get($this->stateKey(), CircuitState::Closed->value))
            ?? CircuitState::Closed;
    }

    public function allowsRequest(): bool
    {
        return match ($this->state()) {
            CircuitState::Closed => true,
            CircuitState::HalfOpen => true,
            CircuitState::Open => $this->tryHalfOpen(),
        };
    }

    public function recordSuccess(): void
    {
        Cache::put($this->failuresKey(), 0, $this->cacheTtl());
        Cache::put($this->stateKey(), CircuitState::Closed->value, $this->cacheTtl());
        Cache::forget($this->openedAtKey());
    }

    public function recordFailure(): void
    {
        $failures = (int) Cache::get($this->failuresKey(), 0) + 1;
        Cache::put($this->failuresKey(), $failures, $this->cacheTtl());

        if ($this->state() === CircuitState::HalfOpen || $failures >= $this->failureThreshold()) {
            Cache::put($this->stateKey(), CircuitState::Open->value, $this->cacheTtl());
            Cache::put($this->openedAtKey(), time(), $this->cacheTtl());
        }
    }

    private function tryHalfOpen(): bool
    {
        $openedAt = (int) Cache::get($this->openedAtKey(), 0);

        if ($openedAt === 0 || (time() - $openedAt) < $this->recoveryTimeoutSeconds()) {
            return false;
        }

        Cache::put($this->stateKey(), CircuitState::HalfOpen->value, $this->cacheTtl());

        return true;
    }

    private function failureThreshold(): int
    {
        return max(1, (int) config('eventflow.circuit_breaker.failure_threshold', 5));
    }

    private function recoveryTimeoutSeconds(): int
    {
        return max(1, (int) config('eventflow.circuit_breaker.recovery_timeout_seconds', 30));
    }

    private function cacheTtl(): int
    {
        return max(60, $this->recoveryTimeoutSeconds() * 10);
    }

    private function stateKey(): string
    {
        return 'eventflow:cb:'.$this->name.':state';
    }

    private function failuresKey(): string
    {
        return 'eventflow:cb:'.$this->name.':failures';
    }

    private function openedAtKey(): string
    {
        return 'eventflow:cb:'.$this->name.':opened_at';
    }
}
