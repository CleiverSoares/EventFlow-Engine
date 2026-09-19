<?php

namespace Tests\Unit;

use App\Services\Locking\LeadProcessingLock;
use Tests\TestCase;

class LeadProcessingLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eventflow.redis.lock_prefix' => 'eventflow:lock:test:',
            'eventflow.redis.lock_ttl_seconds' => 30,
        ]);
    }

    public function test_first_acquire_succeeds_and_second_fails(): void
    {
        $first = new LeadProcessingLock;
        $second = new LeadProcessingLock;

        $this->assertTrue($first->acquire('lead-1'));
        $this->assertFalse($second->acquire('lead-1'));
        $this->assertTrue($second->acquire('lead-2'));

        $first->release('lead-1');
        $second->release('lead-2');
    }

    public function test_release_allows_subsequent_acquire(): void
    {
        $owner = new LeadProcessingLock;
        $waiter = new LeadProcessingLock;

        $this->assertTrue($owner->acquire('lead-release'));
        $this->assertFalse($waiter->acquire('lead-release'));

        $owner->release('lead-release');

        $this->assertTrue($waiter->acquire('lead-release'));
        $waiter->release('lead-release');
    }

    public function test_ttl_expiry_allows_reacquire(): void
    {
        config(['eventflow.redis.lock_ttl_seconds' => 1]);

        $first = new LeadProcessingLock;
        $second = new LeadProcessingLock;

        $this->assertTrue($first->acquire('lead-ttl'));
        $this->assertFalse($second->acquire('lead-ttl'));

        sleep(2);

        $this->assertTrue($second->acquire('lead-ttl'));
        $second->release('lead-ttl');
    }

    public function test_run_returns_false_when_lock_held(): void
    {
        $holder = new LeadProcessingLock;
        $runner = new LeadProcessingLock;

        $holder->acquire('lead-run');

        $executed = false;
        $this->assertFalse($runner->run('lead-run', function () use (&$executed): void {
            $executed = true;
        }));
        $this->assertFalse($executed);

        $holder->release('lead-run');
    }
}
