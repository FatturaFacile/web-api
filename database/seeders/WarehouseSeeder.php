<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';
        $companyId = Schema::hasTable('companies') ? DB::table('companies')->value('id') : null;
        $companyId = $companyId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c';

        $warehouses = [
            [
                'code' => 'WH-MAIN',
                'name' => 'Milano Central Logistics Hub',
                'warehouse_type' => 'standard',
                'address' => 'Via Logistica 10, Milano, 20100, IT',
            ],
            [
                'code' => 'WH-NORTH',
                'name' => 'Bologna Distribution Depot',
                'warehouse_type' => 'standard',
                'address' => 'Interporto Blocco 4, Bologna, 40010, IT',
            ],
            [
                'code' => 'WH-VAN-01',
                'name' => 'Service Van HVAC #1 (Mario Rossi)',
                'warehouse_type' => 'vehicle_van',
                'address' => 'Mobile Unit IT-VA-001, Milano, IT',
            ],
            [
                'code' => 'WH-TRANSIT',
                'name' => 'Inter-branch In-Transit Virtual Warehouse',
                'warehouse_type' => 'transit',
                'address' => 'Virtual Transit Hub',
            ],
            [
                'code' => 'WH-QUARANTINE',
                'name' => 'Central Quarantine & Defect Inspection Depot',
                'warehouse_type' => 'consignment',
                'address' => 'Via Logistica 10 - Area Q, Milano, IT',
            ],
        ];

        foreach ($warehouses as $wh) {
            $existing = DB::table('warehouses')->where('code', $wh['code'])->first();
            $whId = $existing?->id ?? (string) Str::uuid7();

            DB::table('warehouses')->updateOrInsert(
                ['code' => $wh['code']],
                [
                    'id' => $whId,
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'name' => $wh['name'],
                    'warehouse_type' => $wh['warehouse_type'],
                    'address' => $wh['address'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Seed Zone
            $zoneCode = $wh['code'].'-Z1';
            $existingZone = DB::table('warehouse_zones')
                ->where('warehouse_id', $whId)
                ->where('code', $zoneCode)
                ->first();
            $zoneId = $existingZone?->id ?? (string) Str::uuid7();

            DB::table('warehouse_zones')->updateOrInsert(
                ['warehouse_id' => $whId, 'code' => $zoneCode],
                [
                    'id' => $zoneId,
                    'tenant_id' => $tenantId,
                    'name' => 'Primary Storage Zone',
                    'zone_type' => 'storage',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Seed Aisle
            $aisleCode = 'A01';
            $existingAisle = DB::table('warehouse_aisles')
                ->where('warehouse_zone_id', $zoneId)
                ->where('code', $aisleCode)
                ->first();
            $aisleId = $existingAisle?->id ?? (string) Str::uuid7();

            DB::table('warehouse_aisles')->updateOrInsert(
                ['warehouse_zone_id' => $zoneId, 'code' => $aisleCode],
                [
                    'id' => $aisleId,
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Seed Rack
            $rackCode = 'R01';
            $existingRack = DB::table('warehouse_racks')
                ->where('warehouse_aisle_id', $aisleId)
                ->where('code', $rackCode)
                ->first();
            $rackId = $existingRack?->id ?? (string) Str::uuid7();

            DB::table('warehouse_racks')->updateOrInsert(
                ['warehouse_aisle_id' => $aisleId, 'code' => $rackCode],
                [
                    'id' => $rackId,
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Seed Bins
            $bins = [
                ['code' => 'BIN-01', 'barcode' => 'BC-'.$wh['code'].'-01'],
                ['code' => 'BIN-02', 'barcode' => 'BC-'.$wh['code'].'-02'],
                ['code' => 'BIN-03', 'barcode' => 'BC-'.$wh['code'].'-03'],
            ];

            foreach ($bins as $bin) {
                $existingBin = DB::table('warehouse_bins')
                    ->where('warehouse_rack_id', $rackId)
                    ->where('code', $bin['code'])
                    ->first();
                $binId = $existingBin?->id ?? (string) Str::uuid7();

                DB::table('warehouse_bins')->updateOrInsert(
                    ['warehouse_rack_id' => $rackId, 'code' => $bin['code']],
                    [
                        'id' => $binId,
                        'tenant_id' => $tenantId,
                        'barcode' => $bin['barcode'],
                        'max_weight_kg' => 1000.0,
                        'max_volume_cbm' => 5.0,
                        'is_locked' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
