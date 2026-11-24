<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class DiscountPolicySeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        if (! $tenantId) {
            return;
        }

        $policies = [
            [
                'code' => 'TIER_VOLUME_5K',
                'name' => 'Sconto Volume €5k / Tiered Volume Discount €5k / Mengennachlass €5k / Remise sur Volume €5k',
                'discount_type' => 'percentage',
                'value' => 5.00,
                'min_order_amount' => 5000.00,
                'min_quantity' => 10.0,
            ],
            [
                'code' => 'TIER_VOLUME_20K',
                'name' => 'Sconto Volume €20k / Tiered Volume Discount €20k / Mengennachlass €20k / Remise sur Volume €20k',
                'discount_type' => 'percentage',
                'value' => 12.00,
                'min_order_amount' => 20000.00,
                'min_quantity' => 50.0,
            ],
            [
                'code' => 'PROMO_SEASONAL',
                'name' => 'Campagna Promozionale / Seasonal Campaign / Saisonkampagne / Campagne Saisonnière',
                'discount_type' => 'fixed_amount',
                'value' => 250.00,
                'min_order_amount' => 2500.00,
                'min_quantity' => 1.0,
            ],
        ];

        foreach ($policies as $policy) {
            $existing = DB::table('discount_policies')
                ->where('tenant_id', $tenantId)
                ->where('code', $policy['code'])
                ->first();

            if (! $existing) {
                DB::table('discount_policies')->insert(array_merge($policy, [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'valid_from' => now()->startOfYear()->toDateString(),
                    'valid_to' => now()->endOfYear()->toDateString(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }
}
