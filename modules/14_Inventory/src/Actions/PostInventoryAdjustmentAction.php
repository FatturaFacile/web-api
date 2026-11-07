<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\StockAdjustedEvent;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Approves an adjustment that waited for approval: moves the stock of its lines and announces the cost impact to the
 * General Ledger (InventoryAdjustmentPostedEvent). Without lines in the call, the lines sent for approval (the context
 * of its approval workflow) are used.
 */
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
            $adjustment = InventoryAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

            if ($adjustment->status !== 'pending_approval') {
                throw ValidationException::withMessages(['status' => "The adjustment is {$adjustment->status}: only a pending adjustment can be approved."]);
            }

            $lines = $lines !== [] ? $lines : $this->linesSentForApproval($adjustment);

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

                $this->movementService->executeTransfer(new StockTransferDTO(
                    stockItemId: (string) $line['stock_item_id'],
                    sourceBinId: $delta > 0 ? null : (string) $line['warehouse_bin_id'],
                    destinationBinId: $delta > 0 ? (string) $line['warehouse_bin_id'] : null,
                    quantity: $qty,
                    movementType: $delta > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT',
                    lotId: $line['lot_id'] ?? null,
                    originDocumentType: 'inventory_adjustments',
                    originDocumentId: (string) $adjustment->id,
                    userId: $approvedBy,
                    customUnitCost: (float) $line['unit_cost']
                ));
            }

            $this->eventBus->dispatch(new StockAdjustedEvent(
                adjustmentId: (string) $adjustment->id,
                warehouseId: (string) $adjustment->warehouse_id,
                netValuationChange: (float) $adjustment->net_valuation_change,
                status: 'approved',
                companyId: (string) ($adjustment->company_id ?? 'default'),
                userId: $approvedBy,
                tenantId: (string) $adjustment->tenant_id
            ));

            if ($lines !== []) {
                $this->eventBus->publishAndDispatch(InventoryAdjustmentPostedEvent::forAdjustment($adjustment, $lines));
            }

            return $adjustment;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linesSentForApproval(InventoryAdjustment $adjustment): array
    {
        $snapshot = WorkflowInstance::withoutGlobalScopes()
            ->where('approvable_type', InventoryAdjustment::class)
            ->where('approvable_id', (string) $adjustment->id)
            ->latest('created_at')
            ->value('context_snapshot');

        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

        return is_array($snapshot['lines'] ?? null) ? array_values($snapshot['lines']) : [];
    }
}
