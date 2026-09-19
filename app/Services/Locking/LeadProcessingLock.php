<?php

namespace App\Services\Locking;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class LeadProcessingLock
{
    /** @var array<string, Lock> */
    private array $held = [];

    public function acquire(string $leadKey): bool
    {
        $leadKey = $this->normalize($leadKey);

        if (isset($this->held[$leadKey])) {
            return false;
        }

        $lock = Cache::lock($this->key($leadKey), $this->ttlSeconds());

        if (! $lock->get()) {
            return false;
        }

        $this->held[$leadKey] = $lock;

        return true;
    }

    public function release(string $leadKey): void
    {
        $leadKey = $this->normalize($leadKey);

        if (! isset($this->held[$leadKey])) {
            return;
        }

        $this->held[$leadKey]->release();
        unset($this->held[$leadKey]);
    }

    /**
     * @param  callable(): void  $callback
     */
    public function run(string $leadKey, callable $callback): bool
    {
        if (! $this->acquire($leadKey)) {
            return false;
        }

        try {
            $callback();
        } finally {
            $this->release($leadKey);
        }

        return true;
    }

    private function key(string $leadKey): string
    {
        return (string) config('eventflow.redis.lock_prefix', 'eventflow:lock:').$leadKey;
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('eventflow.redis.lock_ttl_seconds', 30));
    }

    private function normalize(string $leadKey): string
    {
        return trim($leadKey);
    }
}
