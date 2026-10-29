<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $this->call(AssetReferenceSeeder::class);

        foreach ($tenants as $tenant) {
            app(TenantContext::class)->setTenant($tenant);

            $categories = DB::table('eam_asset_categories')
                ->where('tenant_id', $tenant->id)
                ->pluck('id', 'code');

            if ($categories->isEmpty()) {
                continue;
            }

            $locId = DB::table('eam_asset_locations')
                ->where('tenant_id', $tenant->id)
                ->where('code', 'LOC-MAIN-PLANT')
                ->value('id') ?: (string) Str::uuid7();

            DB::table('eam_asset_locations')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'code' => 'LOC-MAIN-PLANT'],
                [
                    'id' => $locId,
                    'name' => 'Main Industrial Campus & Logistics Hub',
                    'location_type' => 'facility',
                    'address' => 'Viale dell\'Industria 42',
                    'city' => 'Brescia',
                    'country' => 'IT',
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $demoAssets = [
                // 1. Manufacturing Company Asset: Building
                [
                    'asset_number' => 'AST-MFG-BLD-001',
                    'name' => 'Manufacturing Assembly Hall A (Brescia Plant)',
                    'category_code' => 'BUILDINGS',
                    'cost' => 850000.00,
                    'salvage' => 85000.00,
                    'method' => 'straight_line',
                    'years' => 33,
                    'status' => 'in_service',
                    'sector' => 'Manufacturing',
                ],
                // 2. Manufacturing Company Asset: Equipment / CNC
                [
                    'asset_number' => 'AST-MFG-EQP-002',
                    'name' => 'DMG MORI 5-Axis Robotic Milling Center',
                    'category_code' => 'MACHINERY',
                    'cost' => 180000.00,
                    'salvage' => 12000.00,
                    'method' => 'straight_line',
                    'years' => 8,
                    'status' => 'in_service',
                    'sector' => 'Manufacturing',
                ],
                // 3. Construction Company Asset: Heavy Vehicle
                [
                    'asset_number' => 'AST-CON-VEH-003',
                    'name' => 'Caterpillar 320 Hydraulic Excavator',
                    'category_code' => 'VEHICLES',
                    'cost' => 145000.00,
                    'salvage' => 15000.00,
                    'method' => 'units_of_production',
                    'years' => 5,
                    'status' => 'in_service',
                    'sector' => 'Construction',
                ],
                // 4. Professional Services Asset: Enterprise Server Cluster
                [
                    'asset_number' => 'AST-PRO-SRV-004',
                    'name' => 'NVIDIA DGX / Dell PowerEdge AI Rack Cluster',
                    'category_code' => 'IT_SERVERS',
                    'cost' => 64000.00,
                    'salvage' => 0.00,
                    'method' => 'double_declining_balance',
                    'years' => 5,
                    'status' => 'in_service',
                    'sector' => 'Professional Services',
                ],
                // 5. Professional Services Asset: Software Licenses
                [
                    'asset_number' => 'AST-PRO-LIC-005',
                    'name' => 'Enterprise CAD/BIM Multi-Seat Perpetual License Suite',
                    'category_code' => 'LICENSES',
                    'cost' => 27000.00,
                    'salvage' => 0.00,
                    'method' => 'straight_line',
                    'years' => 3,
                    'status' => 'capitalized',
                    'sector' => 'Professional Services',
                ],
                // 6. Retail Asset: Store Furniture & Automated Shelving
                [
                    'asset_number' => 'AST-RET-FUR-006',
                    'name' => 'Flagship Store Modular Shelving & Smart POS Kiosks',
                    'category_code' => 'FURNITURE',
                    'cost' => 42000.00,
                    'salvage' => 2000.00,
                    'method' => 'straight_line',
                    'years' => 8,
                    'status' => 'in_service',
                    'sector' => 'Retail',
                ],
            ];

            foreach ($demoAssets as $item) {
                $catId = $categories[$item['category_code']] ?? $categories->first();

                $existingAssetId = DB::table('eam_fixed_assets')
                    ->where('tenant_id', $tenant->id)
                    ->where('asset_number', $item['asset_number'])
                    ->value('id');

                DB::table('eam_fixed_assets')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'asset_number' => $item['asset_number'],
                    ],
                    [
                        'id' => $existingAssetId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'category_id' => $catId,
                        'location_id' => $locId,
                        'barcode_tag' => 'TAG-'.$item['asset_number'],
                        'serial_number' => 'SN-'.$item['asset_number'],
                        'name' => $item['name'],
                        'status' => $item['status'],
                        'condition' => 'excellent',
                        'acquisition_type' => 'purchase',
                        'acquisition_date' => date('Y-01-15'),
                        'capitalization_date' => date('Y-01-20'),
                        'in_service_date' => date('Y-02-01'),
                        'currency' => 'EUR',
                        'acquisition_cost' => $item['cost'],
                        'capitalized_cost' => $item['cost'],
                        'salvage_value' => $item['salvage'],
                        'depreciable_base' => $item['cost'] - $item['salvage'],
                        'accumulated_depreciation' => 0.00,
                        'impairment_loss_total' => 0.00,
                        'revaluation_surplus_total' => 0.00,
                        'carrying_amount' => $item['cost'],
                        'replacement_cost' => $item['cost'],
                        'depreciation_method' => $item['method'],
                        'useful_life_years' => $item['years'],
                        'useful_life_months' => $item['years'] * 12,
                        'depreciation_rate_percent' => round(100.0 / $item['years'], 2),
                        'total_expected_units' => 10000.00,
                        'consumed_units' => 0.00,
                        'is_depreciable' => true,
                        'is_fully_depreciated' => false,
                        'approval_status' => 'approved',
                        'is_locked' => false,
                        'specifications' => json_encode(['industry_sector' => $item['sector']]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
