<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\InventoryAdjustmentDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\StockAdjustedEvent;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final readonly class CreateInventoryAdjustmentAction
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movementService,
        private WorkflowEngineInterface $workflowEngine,
        private EventBusInterface $eventBus
    ) {}

    public function execute(InventoryAdjustmentDTO $dto): InventoryAdjustment
    {
        return DB::transaction(function () use ($dto): InventoryAdjustment {
            $totalValuationChange = 0.0;
            foreach ($dto->lines as $line) {
                $totalValuationChange += ((float) $line['quantity_delta']) * ((float) $line['unit_cost']);
            }

            $adjNumber = $dto->adjustmentNumber ?? ('ADJ-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)));
            $requiresApproval = abs($totalValuationChange) > 500.00;

            $adjustment = InventoryAdjustment::create([
                'warehouse_id' => $dto->warehouseId,
                'adjustment_number' => $adjNumber,
                'reason' => $dto->reason,
                'status' => $requiresApproval ? 'pending_approval' : 'approved',
                'net_valuation_change' => $totalValuationChange,
                'is_locked' => $requiresApproval,
                'created_by' => $dto->userId,
                'approved_by' => $requiresApproval ? null : $dto->userId,
                'approved_at' => $requiresApproval ? null : Carbon::now(),
            ]);

            if ($requiresApproval) {
                $this->workflowEngine->start(new StartWorkflowDTO(
                    workflowCode: 'INVENTORY_WRITE_OFF_APPROVAL',
                    approvable: $adjustment,
                    contextSnapshot: [
                        'amount' => abs($totalValuationChange),
                        'reason' => $dto->reason,
                        'warehouse_id' => $dto->warehouseId,
                        'adjustment_id' => $adjustment->id,
                        'lines' => $dto->lines,
                    ],
                    tenantId: (string) ($adjustment->tenant_id ?? 'default'),
                    companyId: (string) ($adjustment->company_id ?? 'default'),
                    initiatorUserId: $dto->userId
                ));
            } else {
                // Execute double entry movements directly
                foreach ($dto->lines as $line) {
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
                        sourceBinId: $sourceBinId,
                        destinationBinId: $destBinId,
                        quantity: $qty,
                        movementType: $movementType,
                        lotId: $line['lot_id'] ?? null,
                        originDocumentType: 'inventory_adjustments',
                        originDocumentId: (string) $adjustment->id,
                        userId: $dto->userId,
                        customUnitCost: (float) $line['unit_cost']
                    ));
                }
            }

            $this->eventBus->dispatch(new StockAdjustedEvent(
                adjustmentId: (string) $adjustment->id,
                warehouseId: $dto->warehouseId,
                netValuationChange: $totalValuationChange,
                status: (string) $adjustment->status,
                companyId: (string) ($adjustment->company_id ?? 'default'),
                userId: $dto->userId,
                tenantId: (string) ($adjustment->tenant_id ?? $tenantId)
            ));

            return $adjustment;
        });
    }
}
