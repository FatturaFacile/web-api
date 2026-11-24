<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class InventoryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';
        $companyId = Schema::hasTable('companies') ? DB::table('companies')->value('id') : null;
        $companyId = $companyId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c';

        $categories = [
            ['code' => 'CAT-HVAC', 'name' => 'HVAC Systems & Components'],
            ['code' => 'CAT-ELEC', 'name' => 'Electrical Installation & Switchgear'],
            ['code' => 'CAT-PLUMB', 'name' => 'Plumbing & Hydraulic Piping'],
            ['code' => 'CAT-CONSUM', 'name' => 'Consumables & Fasteners'],
            ['code' => 'CAT-SPARES', 'name' => 'Spare Parts & Maintenance Kits'],
        ];

        foreach ($categories as $cat) {
            $existing = DB::table('stock_item_categories')->where('code', $cat['code'])->first();
            $catId = $existing?->id ?? (string) Str::uuid7();

            DB::table('stock_item_categories')->updateOrInsert(
                ['code' => $cat['code']],
                [
                    'id' => $catId,
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'name' => $cat['name'],
                    'valuation_method' => 'fifo',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
