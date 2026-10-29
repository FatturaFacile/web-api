<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $tiers = [
            ['code' => 'strategic', 'name' => 'Strategic Tier (Key Enterprise Account)'],
            ['code' => 'enterprise', 'name' => 'Enterprise Account (> 250 Employees)'],
            ['code' => 'mid_market', 'name' => 'Mid-Market Account (50-249 Employees)'],
            ['code' => 'smb', 'name' => 'Small Business (< 50 Employees)'],
            ['code' => 'standard', 'name' => 'Standard Commercial Counterparty'],
        ];

        foreach ($tenants as $tenant) {
            foreach ($tiers as $tier) {
                $exists = DB::table('crm_lead_sources')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', 'cat_'.$tier['code'])
                    ->first();

                if (! $exists) {
                    DB::table('crm_lead_sources')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenant->id,
                        'code' => 'cat_'.$tier['code'],
                        'name' => $tier['name'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('crm_lead_sources')->where('id', $exists->id)->update([
                        'name' => $tier['name'],
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
