<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * Posts the lines of an approved inventory adjustment: one double-entry movement per line (found stock comes in from
 * a virtual location, missing stock goes out to one) and then the cost impact to the General Ledger through
 * InventoryAdjustmentPostedEvent (the ledger's InventoryAccountingListener books inventory against variance). Shared by
 * manual adjustments, approvals of pending adjustments and the approval of physical count differences, so stock and
 * ledger always move together.
 */
final readonly class InventoryAdjustmentPoster
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private EventBusInterface $eventBus
    ) {}

    /**
     * The net value of the lines: sum of quantity delta times unit cost, rounded to cents.
     *
     * @param  array<int, array{quantity_delta: float|int|string, unit_cost: float|int|string}>  $lines
     */
    public function netValue(array $lines): string
    {
        $total = '0';

        foreach ($lines as $line) {
            $total = Quantity::add($total, Quantity::mul($line['quantity_delta'], $line['unit_cost'], 10), 10);
        }

        return Quantity::of($total, 2);
    }

    /**
     * Refuses lines whose bin is not a bin of the adjustment's warehouse.
     *
     * @param  array<int, array{warehouse_bin_id: string}>  $lines
     *
     * @throws StockRuleViolation
     */
    public function assertBinsOfWarehouse(string $warehouseId, array $lines): void
    {
        $binIds = array_values(array_unique(array_map(static fn (array $line): string => (string) $line['warehouse_bin_id'], $lines)));
        $inWarehouse = WarehouseBin::query()
            ->whereIn('id', $binIds)
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId))
            ->count();

        if ($inWarehouse !== count($binIds)) {
            throw new StockRuleViolation('Every adjustment line must name a bin of the adjustment warehouse.');
        }
    }

    /**
     * Moves the stock of the lines and announces the cost impact to the ledger.
     *
     * @param  array<int, array{stock_item_id: string, warehouse_bin_id: string, quantity_delta: float|int|string, unit_cost: float|int|string, lot_id?: ?string}>  $lines
     */
    public function post(InventoryAdjustment $adjustment, array $lines, string $userId, ?string $originType = null, ?string $originId = null, ?string $executedAt = null): void
    {
        $posted = [];

        foreach ($lines as $line) {
            $delta = Quantity::of($line['quantity_delta']);

            if (Quantity::isZero($delta)) {
                continue;
            }

            $inbound = Quantity::isPositive($delta);

            $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: (string) $line['stock_item_id'],
                sourceBinId: $inbound ? null : (string) $line['warehouse_bin_id'],
                destinationBinId: $inbound ? (string) $line['warehouse_bin_id'] : null,
                quantity: (float) Quantity::abs($delta),
                movementType: $inbound ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT',
                lotId: $line['lot_id'] ?? null,
                originDocumentType: $originType ?? 'inventory_adjustments',
                originDocumentId: $originId ?? (string) $adjustment->id,
                userId: $userId,
                customUnitCost: (float) Quantity::of($line['unit_cost']),
                notes: "Inventory adjustment {$adjustment->adjustment_number}",
                executedAt: $executedAt
            ));

            $posted[] = $line;
        }

        if ($posted !== []) {
            $this->eventBus->publishAndDispatch(InventoryAdjustmentPostedEvent::forAdjustment($adjustment, $posted));
        }
    }
}
