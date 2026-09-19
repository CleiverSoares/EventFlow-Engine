<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_requires_api_key(): void
    {
        $this->getJson('/api/ping')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Missing API key.']);
    }

    public function test_ping_rejects_invalid_api_key(): void
    {
        $this->withHeader('X-Api-Key', 'invalid-key')
            ->getJson('/api/ping')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Invalid API key.']);
    }

    public function test_ping_accepts_valid_api_key_and_returns_tenant(): void
    {
        $tenant = Tenant::factory()->pro()->create([
            'api_key' => 'ef_valid_test_key',
            'name' => 'Auth Test Tenant',
        ]);

        $this->withHeader('X-Api-Key', 'ef_valid_test_key')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => 'Auth Test Tenant',
                    'plan' => 'pro',
                ],
            ]);
    }
}
