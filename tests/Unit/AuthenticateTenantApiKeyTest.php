<?php

namespace Tests\Unit;

use App\Http\Middleware\AuthenticateTenantApiKey;
use App\Models\Tenant;
use App\Repositories\TenantRepository;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AuthenticateTenantApiKeyTest extends TestCase
{
    public function test_middleware_binds_tenant_when_api_key_is_valid(): void
    {
        $tenant = new Tenant([
            'name' => 'Unit Tenant',
            'api_key' => 'ef_unit_key',
            'plan' => 'pro',
        ]);
        $tenant->id = '11111111-1111-1111-1111-111111111111';

        $repository = Mockery::mock(TenantRepository::class);
        $repository->shouldReceive('findByApiKey')
            ->once()
            ->with('ef_unit_key')
            ->andReturn($tenant);

        $middleware = new AuthenticateTenantApiKey($repository);
        $request = Request::create('/api/ping', 'GET');
        $request->headers->set('X-Api-Key', 'ef_unit_key');

        $response = $middleware->handle($request, function (Request $req) use ($tenant) {
            $this->assertTrue($tenant->is($req->attributes->get('tenant')));

            return response()->json(['ok' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($tenant->is($request->attributes->get('tenant')));
    }

    public function test_middleware_returns_401_when_api_key_missing(): void
    {
        $repository = Mockery::mock(TenantRepository::class);
        $repository->shouldNotReceive('findByApiKey');

        $middleware = new AuthenticateTenantApiKey($repository);
        $request = Request::create('/api/ping', 'GET');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }
}
