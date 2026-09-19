<?php

namespace App\Console\Commands;

use App\Services\CrmLoadSeedService;
use Illuminate\Console\Command;

class SeedCrmLoadCommand extends Command
{
    protected $signature = 'eventflow:seed-crm-load
        {--whale=2000 : Deal count for whale tenant}
        {--light=100 : Deal count for each light tenant}
        {--accounts=40 : Accounts per tenant}';

    protected $description = 'Seed multi-table CRM data for heavy export load demos';

    public function handle(CrmLoadSeedService $seeds): int
    {
        $tenants = $seeds->ensureDemoTenants();
        $accounts = (int) $this->option('accounts');

        $this->info('Seeding whale '.$tenants['whale']->api_key);
        $whaleDeals = $seeds->seedTenant($tenants['whale'], (int) $this->option('whale'), $accounts);
        $this->info("Whale deals: {$whaleDeals}");

        foreach (['lightA', 'lightB'] as $key) {
            $tenant = $tenants[$key];
            $this->info('Seeding light '.$tenant->api_key);
            $count = $seeds->seedTenant($tenant, (int) $this->option('light'), max(5, (int) ($accounts / 4)));
            $this->info("{$key} deals: {$count}");
        }

        $this->newLine();
        $this->table(['tenant', 'api_key', 'plan'], [
            [$tenants['whale']->name, $tenants['whale']->api_key, $tenants['whale']->plan->value],
            [$tenants['lightA']->name, $tenants['lightA']->api_key, $tenants['lightA']->plan->value],
            [$tenants['lightB']->name, $tenants['lightB']->api_key, $tenants['lightB']->plan->value],
        ]);

        return self::SUCCESS;
    }
}
