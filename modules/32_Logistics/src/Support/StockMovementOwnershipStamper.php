<?php

declare(strict_types=1);

namespace Modules\Logistics\Support;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Logistics\Enums\OwnershipType;

/**
 * Decouples legal ownership from physical location on the stock ledger: every movement is stamped with who legally owns
 * the goods (`ownership_type`), the third party that holds or owns them (`third_party_id`) and whether the movement
 * puts them in transit (`in_transit`), all derived from the warehouse the stock goes to (or leaves, for an issue).
 *
 * Goods on their way are always owned by the company, whatever the destination (a consignment warehouse only holds
 * them once received), except vendor-owned goods, which stay the vendor's.
 */
final class StockMovementOwnershipStamper
{
    /** @var array<string, ?array{warehouse_id: string, zone_type: string}> */
    private static array $bins = [];

    /** @var array<string, ?Warehouse> */
    private static array $warehouses = [];

    public static function register(): void
    {
        StockMovement::creating(static function (StockMovement $movement): void {
            self::stamp($movement);
        });
        Warehouse::saved(static function (): void {
            self::$warehouses = [];
        });
    }

    public static function stamp(StockMovement $movement): void
    {
        $source = self::bin($movement->source_location_id);
        $destination = self::bin($movement->destination_location_id);
        $held = $destination ?? $source;

        if ($held === null) {
            return;
        }

        $warehouse = self::warehouse($held['warehouse_id']);
        if ($warehouse === null) {
            return;
        }

        $inTransit = $destination !== null && $destination['zone_type'] === 'transit';
        $ownership = OwnershipType::forWarehouse($warehouse);
        if ($inTransit && $ownership !== OwnershipType::VendorConsignment) {
            $ownership = OwnershipType::Owned;
        }

        $movement->ownership_type = $ownership->value;
        $movement->third_party_id = $ownership === OwnershipType::Owned ? null : $warehouse->linked_partner_id;
        $movement->in_transit = $inTransit;
    }

    /**
     * @return array{warehouse_id: string, zone_type: string}|null
     */
    private static function bin(mixed $binId): ?array
    {
        if (! is_string($binId) || $binId === '') {
            return null;
        }

        if (! array_key_exists($binId, self::$bins)) {
            $row = DB::table('warehouse_bins as b')
                ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
                ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
                ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
                ->where('b.id', $binId)
                ->select('z.warehouse_id', 'z.zone_type')
                ->first();

            self::$bins[$binId] = $row === null ? null : ['warehouse_id' => (string) $row->warehouse_id, 'zone_type' => (string) $row->zone_type];
        }

        return self::$bins[$binId];
    }

    private static function warehouse(string $warehouseId): ?Warehouse
    {
        return self::$warehouses[$warehouseId] ??= Warehouse::withoutGlobalScopes()->find($warehouseId);
    }
}
