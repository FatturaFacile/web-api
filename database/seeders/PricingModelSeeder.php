<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PricingModelSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $models = [
                ['code' => 'flat_fee', 'name_it' => 'Prezzo Fisso Forfettario', 'name_en' => 'Flat Fee'],
                ['code' => 'per_unit', 'name_it' => 'Prezzo a Utente/Postazione', 'name_en' => 'Per Seat / Per Unit'],
                ['code' => 'tiered', 'name_it' => 'Prezzo a Scaglioni', 'name_en' => 'Tiered Pricing'],
                ['code' => 'volume', 'name_it' => 'Prezzo a Volume', 'name_en' => 'Volume Pricing'],
                ['code' => 'metered', 'name_it' => 'Consumo Metrico', 'name_en' => 'Metered Usage'],
                ['code' => 'hybrid', 'name_it' => 'Modello Ibrido (Canone + Consumo)', 'name_en' => 'Hybrid Bundle'],
            ];

            foreach ($models as $m) {
                DB::table('sub_price_books')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => 'PRICING-'.strtoupper($m['code'])],
                    [
                        'id' => (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $m['name_it'],
                        'currency' => 'EUR',
                        'is_default' => $m['code'] === 'flat_fee',
                        'is_active' => true,
                        'rate_overrides' => json_encode(['pricing_model' => $m['code'], 'description' => $m['name_en']]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
