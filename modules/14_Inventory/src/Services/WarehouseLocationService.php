<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;

/**
 * Adds a location to a warehouse. Stock always sits in a bin, so even a virtual warehouse has one: its zone, aisle and
 * rack are created on the way when they are not there yet.
 */
final class WarehouseLocationService
{
    /**
     * The bin type defaults to the one its zone suggests (a quarantine zone holds quarantine bins, a shipping zone
     * shipping bins, ...), storage otherwise.
     *
     * @param  array{zone_code: string, zone_name?: ?string, zone_type?: ?string, aisle_code: string, rack_code: string, bin_code: string, barcode: string, pick_sequence?: ?int, max_weight_kg?: mixed, max_volume_cbm?: mixed, max_quantity?: mixed, bin_type?: ?string}  $location
     */
    public function addBin(Warehouse $warehouse, array $location): WarehouseBin
    {
        return DB::transaction(function () use ($warehouse, $location): WarehouseBin {
            $zone = WarehouseZone::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'code' => $location['zone_code']],
                ['tenant_id' => $warehouse->tenant_id, 'name' => $location['zone_name'] ?? $location['zone_code'], 'zone_type' => $location['zone_type'] ?? 'storage'],
            );
            $aisle = WarehouseAisle::query()->firstOrCreate(
                ['warehouse_zone_id' => $zone->id, 'code' => $location['aisle_code']],
                ['tenant_id' => $warehouse->tenant_id],
            );
            $rack = WarehouseRack::query()->firstOrCreate(
                ['warehouse_aisle_id' => $aisle->id, 'code' => $location['rack_code']],
                ['tenant_id' => $warehouse->tenant_id],
            );

            return WarehouseBin::create([
                'tenant_id' => $warehouse->tenant_id,
                'warehouse_rack_id' => $rack->id,
                'code' => $location['bin_code'],
                'barcode' => $location['barcode'],
                'pick_sequence' => $location['pick_sequence'] ?? null,
                'max_weight_kg' => $location['max_weight_kg'] ?? null,
                'max_volume_cbm' => $location['max_volume_cbm'] ?? null,
                'max_quantity' => $location['max_quantity'] ?? null,
                'bin_type' => $location['bin_type'] ?? self::binTypeOfZone((string) $zone->zone_type),
            ]);
        });
    }

    /**
     * The natural bin type of a zone type.
     */
    public static function binTypeOfZone(string $zoneType): string
    {
        return match ($zoneType) {
            'receiving' => 'receiving',
            'picking' => 'picking',
            'packing' => 'staging',
            'shipping' => 'shipping',
            'quarantine' => 'quarantine',
            'transit' => 'transit',
            default => 'storage',
        };
    }
}
