<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManufacturingItemSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            $items = [
                // Finished Goods
                [
                    'item_code' => 'FG-PUMP-HD500',
                    'name' => 'Pompa Idraulica Heavy Duty 500 / Heavy Duty Hydraulic Pump 500',
                    'description' => 'Industrial hydraulic pump with cast iron casing (IT: Pompa idraulica per uso gravoso; DE: Hochleistungs-Hydraulikpumpe; FR: Pompe hydraulique haute pression)',
                    'item_type' => 'finished_good',
                    'uom' => 'PCS',
                    'standard_cost' => 450.0000,
                    'current_cost' => 445.0000,
                    'safety_stock' => 10.0000,
                    'min_lot_size' => 1.0000,
                    'lead_time_days' => 5,
                ],
                [
                    'item_code' => 'FG-VALVE-E20',
                    'name' => 'Valvola Elettronica Proporzionale E20 / Electronic Proportional Valve E20',
                    'description' => 'High precision proportional flow valve (IT: Valvola proporzionale; DE: Proportionalventil; FR: Vanne proportionnelle)',
                    'item_type' => 'finished_good',
                    'uom' => 'PCS',
                    'standard_cost' => 280.0000,
                    'current_cost' => 275.0000,
                    'safety_stock' => 15.0000,
                    'min_lot_size' => 5.0000,
                    'lead_time_days' => 4,
                ],
                // Subassemblies
                [
                    'item_code' => 'SA-MOTOR-CORE',
                    'name' => 'Gruppo Motore Elettrico / Electric Motor Core Subassembly',
                    'description' => 'Brushless rotor-stator core module (DE: Motorbaugruppe; FR: Sous-ensemble moteur)',
                    'item_type' => 'subassembly',
                    'uom' => 'PCS',
                    'standard_cost' => 180.0000,
                    'current_cost' => 180.0000,
                    'safety_stock' => 20.0000,
                    'min_lot_size' => 10.0000,
                    'lead_time_days' => 3,
                ],
                // Raw Materials
                [
                    'item_code' => 'RM-STEEL-ROD-40',
                    'name' => 'Barra Acciaio Inox AISI 316 D.40 / Stainless Steel Rod D.40mm',
                    'description' => 'Austenitic stainless steel shaft material (DE: Edelstahlstange; FR: Barre en acier inoxydable)',
                    'item_type' => 'raw_material',
                    'uom' => 'MTR',
                    'standard_cost' => 35.0000,
                    'current_cost' => 34.5000,
                    'safety_stock' => 50.0000,
                    'min_lot_size' => 20.0000,
                    'lead_time_days' => 7,
                ],
                [
                    'item_code' => 'RM-COPPER-WIRE-2',
                    'name' => 'Filo di Rame Smaltato 2mm / Enameled Copper Wire 2mm',
                    'description' => 'High conductivity motor winding wire (DE: Kupferlackdraht; FR: Fil de cuivre émaillé)',
                    'item_type' => 'raw_material',
                    'uom' => 'KG',
                    'standard_cost' => 12.5000,
                    'current_cost' => 12.2000,
                    'safety_stock' => 100.0000,
                    'min_lot_size' => 50.0000,
                    'lead_time_days' => 10,
                ],
                [
                    'item_code' => 'RM-SEAL-VITON-50',
                    'name' => 'Guarnizione O-Ring Viton 50mm / Viton O-Ring Seal 50mm',
                    'description' => 'Fluoropolymer elastomer sealing ring (DE: Viton O-Ring Dichtung; FR: Joint torique Viton)',
                    'item_type' => 'raw_material',
                    'uom' => 'PCS',
                    'standard_cost' => 4.2000,
                    'current_cost' => 4.1000,
                    'safety_stock' => 200.0000,
                    'min_lot_size' => 100.0000,
                    'lead_time_days' => 3,
                ],
            ];

            foreach ($items as $item) {
                DB::table('mfg_items')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'item_code' => $item['item_code'],
                    ],
                    array_merge($item, [
                        'id' => DB::raw("COALESCE((SELECT id FROM mfg_items WHERE tenant_id = '{$tenant->id}' AND item_code = '{$item['item_code']}'), '".Str::uuid7()."')"),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
            }
        }
    }
}
