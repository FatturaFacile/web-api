<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Models\Product;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Services\ProductStockLedger;
use Modules\Inventory\Services\WarehouseDefaultBinResolver;
use Modules\Logistics\Support\LogisticsMessage;

/**
 * Product-level stock per warehouse for the logistics documents (delivery notes, consignment), on top of the one stock
 * ledger of the platform: quants and double-entry movements of the inventory module (ProductStockLedger). Legal
 * ownership and the in-transit flag are stamped on every movement by StockMovementOwnershipStamper, never kept in a
 * second set of balances.
 */
final class WarehouseStockService
{
    /** Movement type (used verbatim, no _in/_out suffix) that re-credits stock when a dispatched document is reversed. */
    public const INBOUND_REVERSAL = 'INBOUND_REVERSAL';

    public function __construct(
        private readonly ProductStockLedger $ledger,
        private readonly WarehouseDefaultBinResolver $bins,
    ) {}

    /**
     * Move stock of a product out of `$fromWarehouseId` and/or into `$toWarehouseId` (null side = outside the company,
     * e.g. a consumption, a write-off or a receipt from a supplier).
     */
    public function move(
        string $productId,
        ?string $fromWarehouseId,
        ?string $toWarehouseId,
        float $quantity,
        string $movementType,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
        ?float $unitCost = null,
    ): void {
        if ($quantity <= 0.0) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.MOVEMENT_QTY_POSITIVE'));
        }
        if ($fromWarehouseId === null && $toWarehouseId === null) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.MOVEMENT_NEEDS_WAREHOUSE'));
        }

        $product = Product::query()->findOrFail($productId);

        if ($fromWarehouseId !== null) {
            $available = $this->available($fromWarehouseId, $productId);
            if ($available < $quantity) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.INSUFFICIENT_STOCK_DETAIL', ['product' => $productId, 'warehouse' => $fromWarehouseId, 'required' => $quantity, 'available' => $available]));
            }
            $this->ledger->issue($product, $fromWarehouseId, $quantity, $this->context($movementType, 'out', $referenceType, $referenceId, $notes, $unitCost));
        }

        if ($toWarehouseId !== null) {
            $this->ledger->receive($product, $toWarehouseId, $quantity, $this->context($movementType, 'in', $referenceType, $referenceId, $notes, $unitCost));
        }
    }

    /** Load opening stock (used by setup flows and tests); recorded as a regular inbound movement. */
    public function receiveOpeningStock(string $warehouseId, string $productId, float $quantity, ?float $unitCost = null): void
    {
        $this->move($productId, null, $warehouseId, $quantity, 'opening_stock', notes: 'Opening stock', unitCost: $unitCost);
    }

    /** Physical stock of a product in a warehouse, not counting what is still on the way (transit bins). */
    public function onHand(string $warehouseId, string $productId): float
    {
        return round((float) $this->quantQuery($warehouseId, $productId)->where('warehouse_zones.zone_type', '!=', 'transit')->sum('stock_quants.quantity_on_hand'), 4);
    }

    /** Stock of a product waiting in the transit bins of a warehouse (transfers shipped to it and not yet received). */
    public function inTransitTo(string $warehouseId, string $productId): float
    {
        return round((float) $this->quantQuery($warehouseId, $productId)->where('warehouse_zones.zone_type', '=', 'transit')->sum('stock_quants.quantity_on_hand'), 4);
    }

    /** Stock of a product that orders can take: on hand minus reserved, in the bins that are not quarantine or transit. */
    public function available(string $warehouseId, string $productId): float
    {
        $binIds = $this->bins->issuableBins($warehouseId)->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        return round((float) $this->quantQuery($warehouseId, $productId)
            ->whereIn('stock_quants.warehouse_bin_id', $binIds)
            ->selectRaw('COALESCE(SUM(stock_quants.quantity_on_hand - stock_quants.quantity_reserved), 0) AS available')
            ->toBase()
            ->value('available'), 4);
    }

    /**
     * @return Builder<StockQuant>
     */
    private function quantQuery(string $warehouseId, string $productId)
    {
        return StockQuant::query()
            ->join('stock_items', 'stock_items.id', '=', 'stock_quants.stock_item_id')
            ->join('warehouse_bins', 'warehouse_bins.id', '=', 'stock_quants.warehouse_bin_id')
            ->join('warehouse_racks', 'warehouse_racks.id', '=', 'warehouse_bins.warehouse_rack_id')
            ->join('warehouse_aisles', 'warehouse_aisles.id', '=', 'warehouse_racks.warehouse_aisle_id')
            ->join('warehouse_zones', 'warehouse_zones.id', '=', 'warehouse_aisles.warehouse_zone_id')
            ->whereNull('stock_quants.deleted_at')
            ->where('stock_items.product_id', $productId)
            ->where('warehouse_zones.warehouse_id', $warehouseId);
    }

    /**
     * @return array{movement_type: string, app_type: string, reference_type: ?string, reference_id: ?string, unit_cost: ?float, notes: ?string}
     */
    private function context(string $movementType, string $direction, ?string $referenceType, ?string $referenceId, ?string $notes, ?float $unitCost): array
    {
        return [
            'movement_type' => $movementType === self::INBOUND_REVERSAL ? $movementType : $movementType.'_'.$direction,
            'app_type' => $direction,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'unit_cost' => $unitCost,
            'notes' => $notes,
        ];
    }
}
