<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class MRPPolicySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            // Update items with standard lot-sizing and lead-time policies
            DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_type', 'raw_material')->update([
                'min_lot_size' => 25.0000,
                'scrap_rate' => 1.50,
            ]);

            DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_type', 'finished_good')->update([
                'min_lot_size' => 5.0000,
                'scrap_rate' => 0.00,
            ]);
        }
    }
}
