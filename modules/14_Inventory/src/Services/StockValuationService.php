<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Events\InventoryValuationCalculatedEvent;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockValuationSnapshot;
use Modules\Inventory\Support\Decimal;
use Modules\Inventory\Support\Quantity;

/**
 * Item-level costing and the valuation read side: the moving average after a receipt, the FIFO cost of a quantity
 * taken out of stock (from the persistent cost layers of InventoryCostingService, FatturaFacile/FatturaFacile#144),
 * item valuation and the daily valuation snapshot per warehouse.
 * Computed with bcmath; the float signatures of the interface are kept for its existing callers.
 */
final class StockValuationService implements StockValuationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly InventoryCostingServiceInterface $costing
    ) {}

    /**
     * The new moving average after receiving `inboundQuantity` units at `inboundUnitCost`, stored on the item. With no
     * stock on hand before the receipt, the receipt cost becomes the average.
     */
    public function computeItemMovingAverageCost(StockItem $item, float $inboundQuantity, float $inboundUnitCost): float
    {
        $onHand = Quantity::of((string) $item->quants()->sum('quantity_on_hand'));
        $inboundQuantity = Quantity::of($inboundQuantity);
        $inboundUnitCost = Quantity::of($inboundUnitCost);

        if (! Quantity::isPositive($onHand)) {
            $newAverage = $inboundUnitCost;
        } else {
            $existingValue = Quantity::mul($onHand, (string) $item->current_moving_average_cost, 10);
            $inboundValue = Quantity::mul($inboundQuantity, $inboundUnitCost, 10);
            $newAverage = Quantity::div(Quantity::add($existingValue, $inboundValue, 10), Quantity::add($onHand, $inboundQuantity)) ?? $inboundUnitCost;
        }

        $item->update(['current_moving_average_cost' => $newAverage]);

        return (float) $newAverage;
    }

    /**
     * Cost of depleting the next $quantityToDeplete units from the open FIFO cost layers
     * (read-only; actual depletion happens in InventoryCostingService::consumeOutbound).
     */
    public function calculateFifoValuation(StockItem $item, float $quantityToDeplete): float
    {
        $remaining = Quantity::of($quantityToDeplete);

        if (! Quantity::isPositive($remaining)) {
            return 0.0;
        }

        return (float) $this->costing->previewFifoCost($item, Decimal::of($quantityToDeplete));
    }

    public function valueItem(StockItem $item, ?string $method = null): string
    {
        $resolved = $method !== null ? ValuationMethod::fromStorage($method) : null;

        return $this->costing->valueOnHand($item, $resolved ?? $this->costing->resolveEffectiveMethod($item));
    }

    /**
     * Today's valuation of the item in a warehouse, with the method of its category, stored once per day.
     */
    public function generateDailySnapshot(StockItem $item, string $warehouseId): StockValuationSnapshot
    {
        return $this->capturePeriodicSnapshot($item, $warehouseId);
    }

    public function capturePeriodicSnapshot(StockItem $item, string $warehouseId, ?string $method = null): StockValuationSnapshot
    {
        $resolved = ($method !== null ? ValuationMethod::fromStorage($method) : null) ?? $this->costing->resolveEffectiveMethod($item);

        $quantity = Decimal::quantity((string) $item->quants()
            ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('quantity_on_hand'));

        $itemOnHand = Decimal::quantity((string) $item->quants()->sum('quantity_on_hand'));
        $itemValue = $this->costing->valueOnHand($item, $resolved);

        // Warehouse share valued at the item-level unit cost of the chosen method.
        $unitCost = Decimal::isPositive($itemOnHand)
            ? Decimal::unitCost(Decimal::div($itemValue, $itemOnHand))
            : Decimal::unitCost(match ($resolved) {
                ValuationMethod::StandardCost => (string) $item->standard_cost,
                default => (string) $item->current_moving_average_cost,
            });

        $totalValuation = Decimal::money(Decimal::mul($quantity, $unitCost));

        $snapshot = StockValuationSnapshot::updateOrCreate(
            [
                'company_id' => $item->company_id ?? $item->tenant_id,
                'warehouse_id' => $warehouseId,
                'stock_item_id' => $item->id,
                'snapshot_date' => Carbon::now()->toDateString(),
            ],
            [
                'tenant_id' => $item->tenant_id,
                'valuation_method' => $resolved->value,
                'quantity_on_hand' => $quantity,
                'unit_cost' => $unitCost,
                'total_valuation' => $totalValuation,
            ]
        );

        $this->dispatcher->publishAndDispatch(new InventoryValuationCalculatedEvent(
            snapshotId: $snapshot->id,
            stockItemId: $item->id,
            warehouseId: $warehouseId,
            valuationMethod: $resolved->value,
            totalValuation: (float) $totalValuation,
            tenantId: $item->tenant_id,
            companyId: (string) ($item->company_id ?? '')
        ));

        return $snapshot;
    }
}
