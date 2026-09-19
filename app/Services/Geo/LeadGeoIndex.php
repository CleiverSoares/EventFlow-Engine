<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Redis;

class LeadGeoIndex
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function maybeIndex(string $member, array $payload): bool
    {
        if (! $this->hasCoordinates($payload)) {
            return false;
        }

        $this->add(
            $member,
            (float) $payload['lng'],
            (float) $payload['lat'],
        );

        return true;
    }

    public function add(string $member, float $longitude, float $latitude): void
    {
        Redis::geoadd($this->key(), $longitude, $latitude, $member);
    }

    /**
     * @return list<string>
     */
    public function membersWithinRadius(
        float $longitude,
        float $latitude,
        float $radiusKm,
    ): array {
        /** @var list<string>|false|null $members */
        $members = Redis::georadius(
            $this->key(),
            $longitude,
            $latitude,
            $radiusKm,
            'km',
        );

        if (! is_array($members)) {
            return [];
        }

        return array_values(array_map(strval(...), $members));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasCoordinates(array $payload): bool
    {
        if (! array_key_exists('lat', $payload) || ! array_key_exists('lng', $payload)) {
            return false;
        }

        if ($payload['lat'] === null || $payload['lng'] === null || $payload['lat'] === '' || $payload['lng'] === '') {
            return false;
        }

        return is_numeric($payload['lat']) && is_numeric($payload['lng']);
    }

    private function key(): string
    {
        return (string) config('eventflow.redis.geo_key', 'eventflow:leads:geo');
    }
}
