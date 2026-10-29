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
                DB::table('crm_lead_sources')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => 'cat_'.$tier['code']],
                    [
                        'id' => (string) Str::uuid7(),
                        'name' => $tier['name'],
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
