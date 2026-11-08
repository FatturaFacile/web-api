<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Events\InventoryValuationCalculatedEvent;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockValuationSnapshot;
use Modules\Inventory\Support\Quantity;

/**
 * Item-level costing: the moving average after a receipt, the FIFO cost of a quantity taken out of stock, and the
 * daily valuation snapshot per warehouse (valued like the stock valuation report, StockValuationReportService).
 * Computed with bcmath; the float signatures of the interface are kept for its existing callers.
 */
final class StockValuationService implements StockValuationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly StockValuationReportService $report
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
     * The FIFO cost of taking `quantityToDeplete` units out of stock: the oldest supplier receipts first. A part not
     * covered by recorded receipts is valued at the moving average (standard cost when there is none).
     */
    public function calculateFifoValuation(StockItem $item, float $quantityToDeplete): float
    {
        $remaining = Quantity::of($quantityToDeplete);

        if (! Quantity::isPositive($remaining)) {
            return 0.0;
        }

        $value = '0';

        foreach (StockMovement::query()->where('stock_item_id', $item->id)->where('movement_type', 'supplier_receipt')->orderBy('executed_at')->orderBy('id')->cursor() as $receipt) {
            if (! Quantity::isPositive($remaining)) {
                break;
            }

            $take = Quantity::min((string) $receipt->quantity, $remaining);
            $value = Quantity::add($value, Quantity::mul($take, (string) $receipt->unit_cost, 10), 10);
            $remaining = Quantity::sub($remaining, $take);
        }

        if (Quantity::isPositive($remaining)) {
            $fallback = Quantity::isPositive((string) $item->current_moving_average_cost) ? (string) $item->current_moving_average_cost : (string) $item->standard_cost;
            $value = Quantity::add($value, Quantity::mul($remaining, $fallback, 10), 10);
        }

        return (float) Quantity::of($value, 2);
    }

    /**
     * Today's valuation of the item in a warehouse, with the method of its category, stored once per day.
     */
    public function generateDailySnapshot(StockItem $item, string $warehouseId): StockValuationSnapshot
    {
        $item->loadMissing('category');
        $method = (string) ($item->category?->valuation_method ?? 'moving_average');
        $quantity = Quantity::of((string) $item->quants()
            ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('quantity_on_hand'));
        $totalOnHand = Quantity::of((string) $item->quants()->sum('quantity_on_hand'));

        $unitCost = $this->report->unitCostAt($item, $method, Carbon::now(), $totalOnHand)['unit_cost'];
        $totalValuation = Quantity::value($quantity, $unitCost);

        $snapshot = StockValuationSnapshot::updateOrCreate(
            [
                'company_id' => $item->company_id ?? $item->tenant_id,
                'warehouse_id' => $warehouseId,
                'stock_item_id' => $item->id,
                'snapshot_date' => Carbon::now()->toDateString(),
            ],
            [
                'tenant_id' => $item->tenant_id,
                'valuation_method' => $method,
                'quantity_on_hand' => $quantity,
                'unit_cost' => $unitCost,
                'total_valuation' => $totalValuation,
            ]
        );

        $this->dispatcher->publishAndDispatch(new InventoryValuationCalculatedEvent(
            snapshotId: $snapshot->id,
            stockItemId: $item->id,
            warehouseId: $warehouseId,
            valuationMethod: $method,
            totalValuation: (float) $totalValuation,
            tenantId: $item->tenant_id,
            companyId: (string) ($item->company_id ?? '')
        ));

        return $snapshot;
    }
}
