<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BOMSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            $pumpItem = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'FG-PUMP-HD500')->first();
            $motorSub = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'SA-MOTOR-CORE')->first();
            $steelRod = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'RM-STEEL-ROD-40')->first();
            $copperWire = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'RM-COPPER-WIRE-2')->first();
            $seal = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'RM-SEAL-VITON-50')->first();

            if (! $pumpItem || ! $motorSub || ! $steelRod || ! $copperWire || ! $seal) {
                continue;
            }

            // 1. Subassembly BOM (Motor Core)
            $existingSub = DB::table('mfg_boms')
                ->where('tenant_id', $tenant->id)
                ->where('bom_number', 'BOM-SA-MOTOR')
                ->where('revision', 'A')
                ->first();
            $subBomId = $existingSub ? $existingSub->id : (string) Str::uuid7();

            DB::table('mfg_boms')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'bom_number' => 'BOM-SA-MOTOR',
                    'revision' => 'A',
                ],
                [
                    'id' => $subBomId,
                    'mfg_item_id' => $motorSub->id,
                    'name' => 'Distinta Base Motore / Motor Core BOM (DE: Stückliste Motorkern; FR: Nomenclature moteur)',
                    'version' => 1,
                    'bom_type' => 'standard',
                    'status' => 'active',
                    'base_quantity' => 1.0000,
                    'uom' => 'PCS',
                    'effective_from' => now()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Subassembly components: copper wire + steel rod
            DB::table('mfg_bom_components')->where('bom_id', $subBomId)->delete();
            DB::table('mfg_bom_components')->insert([
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'bom_id' => $subBomId,
                    'component_item_id' => $copperWire->id,
                    'sequence' => 10,
                    'quantity_per' => 4.5000,
                    'uom' => 'KG',
                    'scrap_percentage' => 2.00,
                    'yield_percentage' => 100.00,
                    'is_phantom' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'bom_id' => $subBomId,
                    'component_item_id' => $steelRod->id,
                    'sequence' => 20,
                    'quantity_per' => 0.7500,
                    'uom' => 'MTR',
                    'scrap_percentage' => 1.00,
                    'yield_percentage' => 100.00,
                    'is_phantom' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // Set default BOM for subassembly
            DB::table('mfg_items')->where('id', $motorSub->id)->update(['default_bom_id' => $subBomId]);

            // 2. Finished Good Multi-level BOM (Pump HD500)
            $existingPump = DB::table('mfg_boms')
                ->where('tenant_id', $tenant->id)
                ->where('bom_number', 'BOM-FG-PUMP-500')
                ->where('revision', 'A')
                ->first();
            $pumpBomId = $existingPump ? $existingPump->id : (string) Str::uuid7();

            DB::table('mfg_boms')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'bom_number' => 'BOM-FG-PUMP-500',
                    'revision' => 'A',
                ],
                [
                    'id' => $pumpBomId,
                    'mfg_item_id' => $pumpItem->id,
                    'name' => 'Distinta Base Pompa HD500 / HD500 Pump Master BOM (DE: Master-Stückliste; FR: Nomenclature principale)',
                    'version' => 1,
                    'bom_type' => 'standard',
                    'status' => 'active',
                    'base_quantity' => 1.0000,
                    'uom' => 'PCS',
                    'effective_from' => now()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Pump components: motor subassembly + steel rod + seals
            DB::table('mfg_bom_components')->where('bom_id', $pumpBomId)->delete();
            DB::table('mfg_bom_components')->insert([
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'bom_id' => $pumpBomId,
                    'component_item_id' => $motorSub->id,
                    'sequence' => 10,
                    'quantity_per' => 1.0000,
                    'uom' => 'PCS',
                    'scrap_percentage' => 0.00,
                    'yield_percentage' => 100.00,
                    'is_phantom' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'bom_id' => $pumpBomId,
                    'component_item_id' => $steelRod->id,
                    'sequence' => 20,
                    'quantity_per' => 1.2000,
                    'uom' => 'MTR',
                    'scrap_percentage' => 3.00,
                    'yield_percentage' => 100.00,
                    'is_phantom' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'bom_id' => $pumpBomId,
                    'component_item_id' => $seal->id,
                    'sequence' => 30,
                    'quantity_per' => 4.0000,
                    'uom' => 'PCS',
                    'scrap_percentage' => 0.00,
                    'yield_percentage' => 100.00,
                    'is_phantom' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // Set default BOM for finished good
            DB::table('mfg_items')->where('id', $pumpItem->id)->update(['default_bom_id' => $pumpBomId]);
        }
    }
}
