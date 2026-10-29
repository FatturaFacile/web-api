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

final class StockValuationService implements StockValuationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher
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

    public function calculateFifoValuation(StockItem $item, float $quantityToDeplete): float
    {
        if ($quantityToDeplete <= 0.0) {
            return 0.0;
        }

        // Retrieve historical supplier receipts in chronological FIFO order
        $inboundMovements = StockMovement::query()
            ->where('stock_item_id', $item->id)
            ->where('movement_type', 'supplier_receipt')
            ->orderBy('executed_at', 'asc')
            ->get();

        $remainingToValue = $quantityToDeplete;
        $accumulatedValuation = 0.0;

        foreach ($inboundMovements as $mv) {
            if ($remainingToValue <= 0.0) {
                break;
            }

            $takeQty = min((float) $mv->quantity, $remainingToValue);
            $accumulatedValuation += $takeQty * (float) $mv->unit_cost;
            $remainingToValue -= $takeQty;
        }

        // If depleted quantity exceeds recorded historical receipts, fall back to moving average or standard cost
        if ($remainingToValue > 0.0) {
            $fallbackCost = (float) $item->current_moving_average_cost > 0.0
                ? (float) $item->current_moving_average_cost
                : (float) $item->standard_cost;
            $accumulatedValuation += $remainingToValue * $fallbackCost;
        }

        return round($accumulatedValuation, 2);
    }

    public function generateDailySnapshot(StockItem $item, string $warehouseId): StockValuationSnapshot
    {
        $item->loadMissing('category');
        $method = $item->category?->valuation_method ?? 'moving_average';
        $quantity = (float) $item->quants()
            ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('quantity_on_hand');

        $unitCost = match ($method) {
            'fifo' => $quantity > 0.0 ? round($this->calculateFifoValuation($item, $quantity) / $quantity, 4) : (float) $item->current_moving_average_cost,
            'standard_cost' => (float) $item->standard_cost,
            default => (float) $item->current_moving_average_cost,
        };

        $totalValuation = round($quantity * $unitCost, 2);

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

        $event = new InventoryValuationCalculatedEvent(
            snapshotId: $snapshot->id,
            stockItemId: $item->id,
            warehouseId: $warehouseId,
            valuationMethod: $method,
            totalValuation: $totalValuation,
            tenantId: $item->tenant_id,
            companyId: (string) ($item->company_id ?? '')
        );
        $this->dispatcher->publish($event);

        return $snapshot;
    }
}
