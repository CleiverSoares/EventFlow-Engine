<?php

namespace Database\Seeders;

use App\Enums\TenantPlan;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::factory()->basic()->create([
            'name' => 'Acme Basic',
            'api_key' => 'ef_demo_basic_key_change_me',
            'plan' => TenantPlan::Basic,
        ]);

        Tenant::factory()->pro()->create([
            'name' => 'Acme Pro',
            'api_key' => 'ef_demo_pro_key_change_me',
            'plan' => TenantPlan::Pro,
        ]);

        Tenant::factory()->enterprise()->create([
            'name' => 'Acme Enterprise',
            'api_key' => 'ef_demo_enterprise_key_change_me',
            'plan' => TenantPlan::Enterprise,
        ]);
    }
}
