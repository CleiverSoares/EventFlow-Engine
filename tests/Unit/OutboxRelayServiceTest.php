<?php

namespace Tests\Unit;

use App\Contracts\MessagePublisher;
use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use App\Observability\InMemoryMetricsRegistry;
use App\Observability\Tracing;
use App\Repositories\OutboxEventRepository;
use App\Services\OutboxRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OutboxRelayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_publish_marks_processed(): void
    {
        $event = OutboxEvent::factory()->pending()->create();

        $publisher = Mockery::mock(MessagePublisher::class);
        $publisher->shouldReceive('publish')->once();

        $service = new OutboxRelayService(
            app(OutboxEventRepository::class),
            $publisher,
            app(Tracing::class),
            app(InMemoryMetricsRegistry::class),
        );
        $result = $service->relayBatch(10);

        $this->assertSame(1, $result['claimed']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(OutboxStatus::Processed, $event->fresh()->status);
    }

    public function test_publish_failure_increments_attempts_and_fails_at_max(): void
    {
        config(['eventflow.outbox.max_attempts' => 2]);

        $event = OutboxEvent::factory()->pending()->create(['attempts' => 1]);

        $publisher = Mockery::mock(MessagePublisher::class);
        $publisher->shouldReceive('publish')->once()->andThrow(new RuntimeException('broker down'));

        $service = new OutboxRelayService(
            app(OutboxEventRepository::class),
            $publisher,
            app(Tracing::class),
            app(InMemoryMetricsRegistry::class),
        );
        $result = $service->relayBatch(10);

        $fresh = $event->fresh();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(2, $fresh->attempts);
        $this->assertSame(OutboxStatus::Failed, $fresh->status);
    }

    public function test_cannot_mark_processed_event_again(): void
    {
        $event = OutboxEvent::factory()->processed()->create();

        $this->expectException(InvalidArgumentException::class);

        app(OutboxEventRepository::class)->markProcessed($event);
    }
}
