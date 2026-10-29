<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class InventoryDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';
        $companyId = Schema::hasTable('companies') ? DB::table('companies')->value('id') : null;
        $companyId = $companyId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c';

        $mainWarehouse = DB::table('warehouses')->where('code', 'WH-MAIN')->first();
        if (! $mainWarehouse) {
            return;
        }

        $hvacCat = DB::table('stock_item_categories')->where('code', 'CAT-HVAC')->first();
        $elecCat = DB::table('stock_item_categories')->where('code', 'CAT-ELEC')->first();

        $storageBin = DB::table('warehouse_bins')
            ->join('warehouse_racks', 'warehouse_bins.warehouse_rack_id', '=', 'warehouse_racks.id')
            ->join('warehouse_aisles', 'warehouse_racks.warehouse_aisle_id', '=', 'warehouse_aisles.id')
            ->join('warehouse_zones', 'warehouse_aisles.warehouse_zone_id', '=', 'warehouse_zones.id')
            ->where('warehouse_zones.warehouse_id', $mainWarehouse->id)
            ->select('warehouse_bins.*')
            ->first();

        if (! $storageBin) {
            return;
        }

        $demoItems = [
            [
                'sku' => 'HVAC-FAN-400W',
                'name' => 'Industrial Axial Centrifugal Fan 400W',
                'uom' => 'PCE',
                'category_id' => $hvacCat?->id,
                'barcode_ean13' => '8001234567890',
                'tracking_type' => 'lot',
                'standard_cost' => 185.00,
                'moving_average_cost' => 182.50,
                'weight_kg' => 12.5,
                'initial_stock' => 30.0,
                'min_stock' => 10.0,
                'max_stock' => 60.0,
                'reorder_point' => 15.0,
                'eoq' => 25.0,
            ],
            [
                'sku' => 'ELEC-BRK-3P-63A',
                'name' => 'Industrial Circuit Breaker 3-Pole 63A 10kA',
                'uom' => 'PCE',
                'category_id' => $elecCat?->id,
                'barcode_ean13' => '8009876543210',
                'tracking_type' => 'serial',
                'standard_cost' => 110.00,
                'moving_average_cost' => 108.00,
                'weight_kg' => 1.8,
                'initial_stock' => 15.0,
                'min_stock' => 5.0,
                'max_stock' => 30.0,
                'reorder_point' => 8.0,
                'eoq' => 15.0,
            ],
            [
                'sku' => 'HVAC-FILTER-HEPA-H14',
                'name' => 'Cleanroom HEPA Filter H14 610x610x150mm',
                'uom' => 'PCE',
                'category_id' => $hvacCat?->id,
                'barcode_ean13' => '8005551234567',
                'tracking_type' => 'none',
                'standard_cost' => 65.00,
                'moving_average_cost' => 64.00,
                'weight_kg' => 4.2,
                'initial_stock' => 100.0,
                'min_stock' => 25.0,
                'max_stock' => 200.0,
                'reorder_point' => 35.0,
                'eoq' => 80.0,
            ],
        ];

        foreach ($demoItems as $itemData) {
            $existingItem = DB::table('stock_items')->where('sku', $itemData['sku'])->first();
            $itemId = $existingItem?->id ?? (string) Str::uuid7();

            DB::table('stock_items')->updateOrInsert(
                ['sku' => $itemData['sku']],
                [
                    'id' => $itemId,
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'category_id' => $itemData['category_id'],
                    'name' => $itemData['name'],
                    'uom' => $itemData['uom'],
                    'barcode_ean13' => $itemData['barcode_ean13'],
                    'tracking_type' => $itemData['tracking_type'],
                    'standard_cost' => $itemData['standard_cost'],
                    'current_moving_average_cost' => $itemData['moving_average_cost'],
                    'weight_kg' => $itemData['weight_kg'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Seed Lot if lot-tracked
            $lotId = null;
            if ($itemData['tracking_type'] === 'lot') {
                $lotNumber = 'LOT-2026-X1';
                $existingLot = DB::table('stock_lots')
                    ->where('stock_item_id', $itemId)
                    ->where('lot_number', $lotNumber)
                    ->first();
                $lotId = $existingLot?->id ?? (string) Str::uuid7();

                DB::table('stock_lots')->updateOrInsert(
                    ['stock_item_id' => $itemId, 'lot_number' => $lotNumber],
                    [
                        'id' => $lotId,
                        'tenant_id' => $tenantId,
                        'company_id' => $companyId,
                        'manufacturing_date' => '2026-01-01',
                        'expiration_date' => '2028-12-31',
                        'is_quarantined' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // Seed Quant in storage bin
            $existingQuant = DB::table('stock_quants')
                ->where('warehouse_bin_id', $storageBin->id)
                ->where('stock_item_id', $itemId)
                ->first();
            $quantId = $existingQuant?->id ?? (string) Str::uuid7();

            DB::table('stock_quants')->updateOrInsert(
                [
                    'warehouse_bin_id' => $storageBin->id,
                    'stock_item_id' => $itemId,
                ],
                [
                    'id' => $quantId,
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'lot_id' => $lotId,
                    'quantity_on_hand' => $itemData['initial_stock'],
                    'quantity_reserved' => 0.0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Seed Reorder Rule
            $existingRule = DB::table('stock_reorder_rules')
                ->where('warehouse_id', $mainWarehouse->id)
                ->where('stock_item_id', $itemId)
                ->first();
            $ruleId = $existingRule?->id ?? (string) Str::uuid7();

            DB::table('stock_reorder_rules')->updateOrInsert(
                [
                    'warehouse_id' => $mainWarehouse->id,
                    'stock_item_id' => $itemId,
                ],
                [
                    'id' => $ruleId,
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'min_stock_level' => $itemData['min_stock'],
                    'max_stock_level' => $itemData['max_stock'],
                    'reorder_point' => $itemData['reorder_point'],
                    'economic_order_quantity' => $itemData['eoq'],
                    'lead_time_days' => 5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
