<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InventoryTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';
        $companyId = Schema::hasTable('companies') ? DB::table('companies')->value('id') : null;
        $companyId = $companyId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c';

        // 1. Test Warehouse
        $warehouseId = '0192b111-0000-7000-8000-000000000001';
        DB::table('warehouses')->updateOrInsert(
            ['id' => $warehouseId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'code' => 'WH-TEST-MAIN',
                'name' => 'Main Test Logistics Hub',
                'warehouse_type' => 'standard',
                'address' => 'Via Test Logistica 100, Milano, IT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Test Zone
        $zoneId = '0192b111-0000-7000-8000-000000000002';
        DB::table('warehouse_zones')->updateOrInsert(
            ['id' => $zoneId],
            [
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'code' => 'ZONE-A',
                'name' => 'General Pallet Storage Zone',
                'zone_type' => 'storage',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 3. Test Aisle
        $aisleId = '0192b111-0000-7000-8000-000000000009';
        DB::table('warehouse_aisles')->updateOrInsert(
            ['id' => $aisleId],
            [
                'tenant_id' => $tenantId,
                'warehouse_zone_id' => $zoneId,
                'code' => 'A01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 4. Test Rack
        $rackId = '0192b111-0000-7000-8000-000000000010';
        DB::table('warehouse_racks')->updateOrInsert(
            ['id' => $rackId],
            [
                'tenant_id' => $tenantId,
                'warehouse_aisle_id' => $aisleId,
                'code' => 'R01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 5. Test Bins
        $inboundBinId = '0192b111-0000-7000-8000-000000000003';
        DB::table('warehouse_bins')->updateOrInsert(
            ['id' => $inboundBinId],
            [
                'tenant_id' => $tenantId,
                'warehouse_rack_id' => $rackId,
                'code' => 'BIN-INBOUND-01',
                'barcode' => '8001234567890-IN',
                'max_weight_kg' => 2000.0,
                'max_volume_cbm' => 10.0,
                'is_locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $storageBinId = '0192b111-0000-7000-8000-000000000004';
        DB::table('warehouse_bins')->updateOrInsert(
            ['id' => $storageBinId],
            [
                'tenant_id' => $tenantId,
                'warehouse_rack_id' => $rackId,
                'code' => 'BIN-A-01-01',
                'barcode' => '8001234567890-STORAGE',
                'max_weight_kg' => 1500.0,
                'max_volume_cbm' => 8.0,
                'is_locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 6. Test Category
        $categoryId = '0192b111-0000-7000-8000-000000000005';
        DB::table('stock_item_categories')->updateOrInsert(
            ['id' => $categoryId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'code' => 'CAT-TEST-ELEC',
                'name' => 'Test Electronic Components',
                'valuation_method' => 'fifo',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 7. Test Stock Item
        $itemId = '0192b111-0000-7000-8000-000000000006';
        DB::table('stock_items')->updateOrInsert(
            ['id' => $itemId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'category_id' => $categoryId,
                'sku' => 'TEST-SKU-CONTROLLER',
                'name' => 'Microcontroller Board Enterprise MCU-X',
                'barcode_ean13' => '8001234567890',
                'uom' => 'PCE',
                'tracking_type' => 'lot',
                'standard_cost' => 45.00,
                'current_moving_average_cost' => 45.00,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 8. Test Stock Lot
        $lotId = '0192b111-0000-7000-8000-000000000007';
        DB::table('stock_lots')->updateOrInsert(
            ['id' => $lotId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'stock_item_id' => $itemId,
                'lot_number' => 'LOT-TEST-2026-001',
                'manufacturing_date' => '2026-01-01',
                'expiration_date' => '2028-12-31',
                'is_quarantined' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 9. Initial Quant
        DB::table('stock_quants')->updateOrInsert(
            [
                'warehouse_bin_id' => $storageBinId,
                'stock_item_id' => $itemId,
                'lot_id' => $lotId,
            ],
            [
                'id' => '0192b111-0000-7000-8000-000000000008',
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'quantity_on_hand' => 100.0,
                'quantity_reserved' => 0.0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
