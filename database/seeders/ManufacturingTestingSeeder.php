<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManufacturingTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            $existingWc = DB::table('mfg_work_centers')->where('tenant_id', $tenant->id)->where('work_center_code', 'WC-TEST-01')->first();
            $wcId = $existingWc ? $existingWc->id : (string) Str::uuid7();

            DB::table('mfg_work_centers')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'work_center_code' => 'WC-TEST-01'],
                [
                    'id' => $wcId,
                    'name' => 'Testing Work Center',
                    'capacity_hours_per_day' => 8.00,
                    'efficiency_rate' => 100.00,
                    'availability_rate' => 100.00,
                    'hourly_cost_rate' => 50.0000,
                    'hourly_overhead_rate' => 20.0000,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $existingParent = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'TEST-FG-ASSEMBLY')->first();
            $parentId = $existingParent ? $existingParent->id : (string) Str::uuid7();

            DB::table('mfg_items')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'item_code' => 'TEST-FG-ASSEMBLY'],
                [
                    'id' => $parentId,
                    'name' => 'Testing Finished Assembly',
                    'item_type' => 'finished_good',
                    'uom' => 'PCS',
                    'standard_cost' => 100.0000,
                    'safety_stock' => 10.0000,
                    'lead_time_days' => 2,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $existingChild = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'TEST-RM-COMPONENT')->first();
            $childId = $existingChild ? $existingChild->id : (string) Str::uuid7();

            DB::table('mfg_items')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'item_code' => 'TEST-RM-COMPONENT'],
                [
                    'id' => $childId,
                    'name' => 'Testing Raw Component',
                    'item_type' => 'raw_material',
                    'uom' => 'PCS',
                    'standard_cost' => 15.0000,
                    'safety_stock' => 50.0000,
                    'lead_time_days' => 3,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $existingBom = DB::table('mfg_boms')->where('tenant_id', $tenant->id)->where('bom_number', 'BOM-TEST-001')->first();
            $bomId = $existingBom ? $existingBom->id : (string) Str::uuid7();

            DB::table('mfg_boms')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'bom_number' => 'BOM-TEST-001'],
                [
                    'id' => $bomId,
                    'mfg_item_id' => $parentId,
                    'name' => 'Testing BOM Spec',
                    'revision' => 'A',
                    'version' => 1,
                    'bom_type' => 'standard',
                    'status' => 'active',
                    'base_quantity' => 1.0000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('mfg_bom_components')->where('bom_id', $bomId)->delete();
            DB::table('mfg_bom_components')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'bom_id' => $bomId,
                'component_item_id' => $childId,
                'sequence' => 10,
                'quantity_per' => 2.0000,
                'uom' => 'PCS',
                'scrap_percentage' => 0.00,
                'yield_percentage' => 100.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('mfg_items')->where('id', $parentId)->update(['default_bom_id' => $bomId]);

            $existingRouting = DB::table('mfg_routings')->where('tenant_id', $tenant->id)->where('routing_number', 'RTG-TEST-001')->first();
            $routingId = $existingRouting ? $existingRouting->id : (string) Str::uuid7();

            DB::table('mfg_routings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'routing_number' => 'RTG-TEST-001'],
                [
                    'id' => $routingId,
                    'mfg_item_id' => $parentId,
                    'name' => 'Testing Routing Spec',
                    'status' => 'active',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('mfg_operations')->where('routing_id', $routingId)->delete();
            DB::table('mfg_operations')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'routing_id' => $routingId,
                'operation_code' => 'OP-TEST-01',
                'name' => 'Testing Operation Step',
                'work_center_id' => $wcId,
                'setup_time_minutes' => 10.00,
                'run_time_minutes_per_unit' => 5.00,
                'labor_time_minutes_per_unit' => 5.00,
                'standard_labor_rate' => 25.0000,
                'standard_machine_rate' => 45.0000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('mfg_items')->where('id', $parentId)->update(['default_routing_id' => $routingId]);
        }
    }
}
