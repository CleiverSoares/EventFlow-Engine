<?php

namespace Tests\Unit;

use App\Services\Geo\LeadGeoIndex;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class LeadGeoIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['eventflow.redis.geo_key' => 'eventflow:leads:geo:test']);
    }

    public function test_add_and_radius_returns_nearby_member(): void
    {
        Redis::shouldReceive('geoadd')
            ->once()
            ->with('eventflow:leads:geo:test', -46.6333, -23.5505, 'lead-sp')
            ->andReturn(1);

        Redis::shouldReceive('georadius')
            ->once()
            ->with('eventflow:leads:geo:test', -46.6333, -23.5505, 5.0, 'km')
            ->andReturn(['lead-sp']);

        $geo = new LeadGeoIndex;
        $geo->add('lead-sp', -46.6333, -23.5505);

        $this->assertSame(['lead-sp'], $geo->membersWithinRadius(-46.6333, -23.5505, 5.0));
    }

    public function test_maybe_index_skips_when_coordinates_missing(): void
    {
        Redis::shouldReceive('geoadd')->never();

        $geo = new LeadGeoIndex;

        $this->assertFalse($geo->maybeIndex('lead-1', ['cnpj' => '12345678000199']));
        $this->assertFalse($geo->maybeIndex('lead-2', ['lat' => -23.5]));
        $this->assertFalse($geo->maybeIndex('lead-3', ['lat' => null, 'lng' => -46.6]));
    }

    public function test_maybe_index_adds_when_coordinates_present(): void
    {
        Redis::shouldReceive('geoadd')
            ->once()
            ->with('eventflow:leads:geo:test', -46.6, -23.5, 'lead-coords')
            ->andReturn(1);

        $geo = new LeadGeoIndex;

        $this->assertTrue($geo->maybeIndex('lead-coords', [
            'cnpj' => '12345678000199',
            'lat' => -23.5,
            'lng' => -46.6,
        ]));
    }
}
