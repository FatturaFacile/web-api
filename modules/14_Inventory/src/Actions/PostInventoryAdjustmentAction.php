<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\StockAdjustedEvent;
use Modules\Inventory\Models\InventoryAdjustment;

final readonly class PostInventoryAdjustmentAction
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movementService,
        private EventBusInterface $eventBus
    ) {}

    /**
     * @param  array<int, array{stock_item_id: string, warehouse_bin_id: string, quantity_delta: float, unit_cost: float, lot_id?: ?string}>  $lines
     */
    public function execute(InventoryAdjustment $adjustment, array $lines, string $approvedBy): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $lines, $approvedBy): InventoryAdjustment {
            $adjustment->update([
                'status' => 'approved',
                'is_locked' => false,
                'approved_by' => $approvedBy,
                'approved_at' => Carbon::now(),
            ]);

            foreach ($lines as $line) {
                $delta = (float) $line['quantity_delta'];
                $qty = abs($delta);
                if ($qty <= 0.0) {
                    continue;
                }

                $movementType = $delta > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT';
                $sourceBinId = $delta > 0 ? null : (string) $line['warehouse_bin_id'];
                $destBinId = $delta > 0 ? (string) $line['warehouse_bin_id'] : null;

                $this->movementService->executeTransfer(new StockTransferDTO(
                    stockItemId: (string) $line['stock_item_id'],
                    warehouseId: (string) $adjustment->warehouse_id,
                    quantity: $qty,
                    movementType: $movementType,
                    sourceBinId: $sourceBinId,
                    destinationBinId: $destBinId,
                    stockLotId: $line['lot_id'] ?? null,
                    unitCost: (float) $line['unit_cost'],
                    referenceType: 'inventory_adjustments',
                    referenceId: (string) $adjustment->id,
                    userId: $approvedBy,
                    companyId: $adjustment->company_id
                ));
            }

            $this->eventBus->dispatch(new StockAdjustedEvent(
                adjustmentId: (string) $adjustment->id,
                warehouseId: (string) $adjustment->warehouse_id,
                netValuationChange: (float) $adjustment->net_valuation_change,
                status: 'approved',
                companyId: (string) ($adjustment->company_id ?? 'default'),
                userId: $approvedBy
            ));

            return $adjustment;
        });
    }
}
