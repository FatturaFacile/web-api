<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BillingPlanSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $plans = [
                [
                    'code' => 'SAAS-STARTER-M',
                    'interval' => 'monthly',
                    'count' => 1,
                    'model' => 'flat_fee',
                    'price' => 49.00,
                    'name_it' => 'Piano Cloud Starter Mensile',
                    'name_en' => 'Starter Cloud Monthly Plan',
                    'name_de' => 'Starter Cloud Monatstarif',
                    'name_fr' => 'Forfait Cloud Starter Mensuel',
                ],
                [
                    'code' => 'SAAS-PRO-M',
                    'interval' => 'monthly',
                    'count' => 1,
                    'model' => 'per_unit',
                    'price' => 149.00,
                    'name_it' => 'Piano Cloud Professional Mensile',
                    'name_en' => 'Professional Cloud Monthly Plan',
                    'name_de' => 'Professional Cloud Monatstarif',
                    'name_fr' => 'Forfait Cloud Professionnel Mensuel',
                ],
                [
                    'code' => 'SAAS-ENT-ANNUAL',
                    'interval' => 'annual',
                    'count' => 1,
                    'model' => 'hybrid',
                    'price' => 2400.00,
                    'name_it' => 'Piano Enterprise Annuale Ibrido',
                    'name_en' => 'Enterprise Annual Hybrid Plan',
                    'name_de' => 'Enterprise Jahres-Hybridtarif',
                    'name_fr' => 'Forfait Entreprise Annuel Hybride',
                ],
                [
                    'code' => 'SAAS-METERED-USAGE',
                    'interval' => 'monthly',
                    'count' => 1,
                    'model' => 'metered',
                    'price' => 0.00,
                    'name_it' => 'Piano a Consumo / Pay-as-you-go',
                    'name_en' => 'Metered Usage / Pay-as-you-go Plan',
                    'name_de' => 'Verbrauchsabhängiger Nutzertarif',
                    'name_fr' => 'Forfait Facturation à l\'Usage',
                ],
            ];

            foreach ($plans as $p) {
                $existing = DB::table('sub_plans')
                    ->where('tenant_id', $tenant->id)
                    ->where('plan_code', $p['code'])
                    ->first();

                $planId = $existing?->id ?? (string) Str::uuid7();

                DB::table('sub_plans')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'plan_code' => $p['code']],
                    [
                        'id' => $planId,
                        'company_id' => $tenant->id,
                        'name' => $p['name_it'],
                        'description' => $p['name_en'],
                        'billing_interval' => $p['interval'],
                        'interval_count' => $p['count'],
                        'pricing_model' => $p['model'],
                        'currency' => 'EUR',
                        'base_price' => $p['price'],
                        'is_active' => true,
                        'translations' => json_encode([
                            'it' => ['name' => $p['name_it']],
                            'en' => ['name' => $p['name_en']],
                            'de' => ['name' => $p['name_de']],
                            'fr' => ['name' => $p['name_fr']],
                        ]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
