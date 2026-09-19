<?php

namespace Tests\Unit;

use Tests\TestCase;

class EventflowConfigTest extends TestCase
{
    public function test_eventflow_mode_resolves_from_config(): void
    {
        config(['eventflow.mode' => 'phase2']);

        $this->assertSame('phase2', config('eventflow.mode'));
    }

    public function test_eventflow_config_file_exposes_expected_defaults(): void
    {
        $this->assertContains(config('eventflow.mode'), ['phase1', 'phase2']);
        $this->assertNotEmpty(config('eventflow.enrichment.base_url'));
        $this->assertSame('leads.incoming', config('eventflow.rabbitmq.queue'));
        $this->assertArrayHasKey('basic', config('eventflow.rate_limits'));
    }
}
