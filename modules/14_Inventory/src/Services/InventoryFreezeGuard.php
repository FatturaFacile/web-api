<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\WarehouseBin;

/**
 * Physical inventory freeze. While a count session that freezes stock is open (in progress, or with its differences
 * waiting for approval), no movement may touch the
 * bins it counts: its whole warehouse, or only its zone when the session is limited to one. Otherwise the stock would
 * change under the counters and the differences would be wrong. The only movements allowed are the ones the session
 * itself posts (the count adjustments). A freeze is opt-in per session (or per cycle count plan).
 */
final class InventoryFreezeGuard
{
    /**
     * @param  list<WarehouseBin>  $bins  the source and destination bins of the movement
     *
     * @throws StockRuleViolation when one of the bins is frozen by another count session
     */
    public function assertMovable(array $bins, ?string $originDocumentType, ?string $originDocumentId): void
    {
        if ($bins === []) {
            return;
        }

        $locations = WarehouseBin::withoutGlobalScopes()
            ->join('warehouse_racks as r', 'r.id', '=', 'warehouse_bins.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->whereIn('warehouse_bins.id', array_map(static fn (WarehouseBin $bin): string => (string) $bin->id, $bins))
            ->get(['warehouse_bins.barcode', 'z.id as zone_id', 'z.warehouse_id as warehouse_id']);

        foreach ($locations as $location) {
            $freezing = InventoryCountSession::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('freezes_stock', true)
                ->whereIn('status', InventoryCountService::OPEN_STATUSES)
                ->where('warehouse_id', $location->getAttribute('warehouse_id'))
                ->where(static fn ($scope) => $scope->whereNull('warehouse_zone_id')->orWhere('warehouse_zone_id', $location->getAttribute('zone_id')))
                ->when(
                    $originDocumentType === InventoryCountSession::class && $originDocumentId !== null,
                    static fn ($query) => $query->whereKeyNot($originDocumentId)
                )
                ->first(['id', 'session_number']);

            if ($freezing !== null) {
                throw new StockRuleViolation("Bin [{$location->barcode}] is frozen by the physical count {$freezing->session_number}: no stock may move there until the count is approved or cancelled.");
            }
        }
    }
}
