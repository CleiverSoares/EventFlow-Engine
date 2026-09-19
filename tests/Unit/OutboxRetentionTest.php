<?php

namespace Tests\Unit;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Services\OutboxRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OutboxRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_old_processed_rows(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');

        $tenant = Tenant::factory()->create();

        $old = OutboxEvent::factory()->processed()->create([
            'tenant_id' => $tenant->id,
            'updated_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);
        $fresh = OutboxEvent::factory()->processed()->create([
            'tenant_id' => $tenant->id,
            'updated_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        $pending = OutboxEvent::factory()->pending()->create([
            'tenant_id' => $tenant->id,
            'updated_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);

        $deleted = app(OutboxRetentionService::class)->pruneProcessed(7);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('outbox_events', ['id' => $old->id]);
        $this->assertDatabaseHas('outbox_events', ['id' => $fresh->id]);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $pending->id,
            'status' => OutboxStatus::Pending->value,
        ]);

        Carbon::setTestNow();
    }
}
