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

/**
 * Valuation read-side: summaries and snapshots, built on the persistent cost layers of
 * InventoryCostingService (FatturaFacile/FatturaFacile#144).
 */
final class StockValuationService implements StockValuationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly InventoryCostingServiceInterface $costing
    ) {}

    public function computeItemMovingAverageCost(StockItem $item, float $inboundQuantity, float $inboundUnitCost): float
    {
        $currentOnHand = $item->total_on_hand;
        $currentMac = (float) $item->current_moving_average_cost;

        if ($currentOnHand <= 0.0) {
            $newMac = $inboundUnitCost;
        } else {
            $totalExistingValuation = $currentOnHand * $currentMac;
            $inboundValuation = $inboundQuantity * $inboundUnitCost;
            $newTotalQuantity = $currentOnHand + $inboundQuantity;

            $newMac = $newTotalQuantity > 0.0 ? ($totalExistingValuation + $inboundValuation) / $newTotalQuantity : $inboundUnitCost;
        }

        $newMac = round($newMac, 4);
        $item->update(['current_moving_average_cost' => $newMac]);

        return $newMac;
    }

    /**
     * Cost of depleting the next $quantityToDeplete units from the open FIFO cost layers
     * (read-only; actual depletion happens in InventoryCostingService::consumeOutbound).
     */
    public function calculateFifoValuation(StockItem $item, float $quantityToDeplete): float
    {
        if ($quantityToDeplete <= 0.0) {
            return 0.0;
        }

        return (float) $this->costing->previewFifoCost($item, Decimal::of($quantityToDeplete));
    }

    public function valueItem(StockItem $item, ?string $method = null): string
    {
        $resolved = $method !== null ? ValuationMethod::fromStorage($method) : null;

        return $this->costing->valueOnHand($item, $resolved ?? $this->costing->resolveEffectiveMethod($item));
    }

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

        $event = new InventoryValuationCalculatedEvent(
            snapshotId: $snapshot->id,
            stockItemId: $item->id,
            warehouseId: $warehouseId,
            valuationMethod: $resolved->value,
            totalValuation: (float) $totalValuation,
            tenantId: $item->tenant_id,
            companyId: (string) ($item->company_id ?? '')
        );
        $this->dispatcher->publish($event);

        return $snapshot;
    }
}
