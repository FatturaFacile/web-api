<?php

declare(strict_types=1);

namespace Modules\Wms\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only lookup of the bins of the inventory module with the warehouse and zone they belong to
 * (bin > rack > aisle > zone > warehouse), so the WMS never needs a column of the inventory tables to know the warehouse
 * of a bin.
 */
final class WarehouseBins
{
    /**
     * Bins of the company joined to their zone: id, code, bin_type, is_locked, pick_sequence, zone_id, warehouse_id.
     */
    public static function query(string $tenantId): Builder
    {
        return DB::table('warehouse_bins as b')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->where('b.tenant_id', $tenantId)
            ->whereNull('b.deleted_at')
            ->select(['b.id', 'b.code', 'b.bin_type', 'b.is_locked', 'b.pick_sequence', 'z.id as zone_id', 'z.warehouse_id']);
    }

    public static function find(string $tenantId, string $binId): ?object
    {
        return self::query($tenantId)->where('b.id', $binId)->first();
    }
}
