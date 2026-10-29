<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SLAPolicySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $plans = [
            [
                'code' => 'PLAN_ENTERPRISE_247',
                'name' => 'Enterprise Mission Critical 24/7',
                'tier' => 'mission_critical',
                'response_time_hours' => 1.00,
                'resolution_time_hours' => 4.00,
                'price' => 4500.00,
                'currency' => 'EUR',
                'translations' => [
                    'it' => 'Enterprise Mission Critical 24/7',
                    'en' => 'Enterprise Mission Critical 24/7',
                    'de' => 'Enterprise Unternehmenskritisch 24/7',
                    'fr' => 'Entreprise Mission Critique 24/7',
                ],
            ],
            [
                'code' => 'PLAN_BUSINESS_GOLD',
                'name' => 'Business Gold 8x5',
                'tier' => 'premium',
                'response_time_hours' => 4.00,
                'resolution_time_hours' => 24.00,
                'price' => 1800.00,
                'currency' => 'EUR',
                'translations' => [
                    'it' => 'Business Gold 8x5',
                    'en' => 'Business Gold 8x5',
                    'de' => 'Business Gold 8x5',
                    'fr' => 'Affaires Or 8x5',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($plans as $p) {
                $existingId = DB::table('esm_support_plans')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $p['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('esm_support_plans')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $p['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $p['name'],
                        'tier' => $p['tier'],
                        'response_time_hours' => $p['response_time_hours'],
                        'resolution_time_hours' => $p['resolution_time_hours'],
                        'price' => $p['price'],
                        'currency' => $p['currency'],
                        'billing_cycle' => 'monthly',
                        'is_active' => true,
                        'translations' => json_encode($p['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
