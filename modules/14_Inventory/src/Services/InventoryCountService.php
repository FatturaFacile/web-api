<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\InventoryCountCompletedEvent;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final class InventoryCountService implements InventoryCountServiceInterface
{
    public function __construct(
        private readonly DoubleEntryMovementServiceInterface $movementService,
        private readonly EventDispatcher $dispatcher,
        private readonly WorkflowEngineInterface $workflowEngine
    ) {}

    public function createSession(InventoryCountSessionDTO $dto): InventoryCountSession
    {
        return DB::transaction(function () use ($dto): InventoryCountSession {
            $warehouse = Warehouse::findOrFail($dto->warehouseId);
            $sessionNumber = $dto->sessionNumber ?? 'CNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(4));

            $session = InventoryCountSession::create([
                'tenant_id' => $warehouse->tenant_id,
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'session_number' => $sessionNumber,
                'count_type' => $dto->countType,
                'status' => 'in_progress',
                'scheduled_date' => $dto->scheduledDate,
                'is_locked' => false,
                'created_by' => $dto->userId,
            ]);

            // Populate lines from existing quants in warehouse bins
            $query = StockQuant::query()
                ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $warehouse->id))
                ->with(['bin', 'item']);

            if (! empty($dto->binIds)) {
                $query->whereIn('warehouse_bin_id', $dto->binIds);
            }

            $quants = $query->get();
            foreach ($quants as $quant) {
                InventoryCountLine::create([
                    'tenant_id' => $session->tenant_id,
                    'inventory_count_session_id' => $session->id,
                    'warehouse_bin_id' => $quant->warehouse_bin_id,
                    'stock_item_id' => $quant->stock_item_id,
                    'lot_id' => $quant->lot_id,
                    'system_quantity' => (float) $quant->quantity_on_hand,
                    'counted_quantity' => null,
                    'discrepancy_quantity' => 0.0,
                    'discrepancy_valuation' => 0.0,
                ]);
            }

            return $session;
        });
    }

    public function recordCount(string $sessionLineId, float $countedQuantity, string $userId): void
    {
        $line = InventoryCountLine::with('item')->findOrFail($sessionLineId);
        $systemQty = (float) $line->system_quantity;
        $delta = $countedQuantity - $systemQty;
        $unitCost = (float) ($line->item?->current_moving_average_cost ?? 0.0);
        $valuation = round($delta * $unitCost, 2);

        $line->update([
            'counted_quantity' => $countedQuantity,
            'discrepancy_quantity' => $delta,
            'discrepancy_valuation' => $valuation,
            'counted_by_user_id' => $userId,
            'counted_at' => Carbon::now(),
        ]);
    }

    public function reconcileSession(InventoryCountSession $session, string $userId): InventoryAdjustment
    {
        return DB::transaction(function () use ($session, $userId): InventoryAdjustment {
            $lines = $session->lines()->with('item')->get();
            $totalNetValuationChange = (float) $lines->sum('discrepancy_valuation');
            $adjNumber = 'ADJ-'.now()->format('Ymd').'-'.strtoupper(Str::random(4));

            // Threshold: If net negative variance exceeds €500, requires Workflow Engine V2 approval
            $requiresApproval = abs($totalNetValuationChange) > 500.00;

            $adjustment = InventoryAdjustment::create([
                'tenant_id' => $session->tenant_id,
                'company_id' => $session->company_id,
                'warehouse_id' => $session->warehouse_id,
                'adjustment_number' => $adjNumber,
                'reason' => 'cycle_count_variance',
                'status' => $requiresApproval ? 'pending_approval' : 'approved',
                'net_valuation_change' => $totalNetValuationChange,
                'is_locked' => $requiresApproval,
                'created_by' => $userId,
                'approved_by' => $requiresApproval ? null : $userId,
                'approved_at' => $requiresApproval ? null : Carbon::now(),
            ]);

            if ($requiresApproval) {
                // Route to Module 21 Workflow Engine V2
                $this->workflowEngine->start(new StartWorkflowDTO(
                    workflowCode: 'INVENTORY_WRITE_OFF_APPROVAL',
                    approvable: $adjustment,
                    contextSnapshot: [
                        'session_number' => $session->session_number,
                        'variance_amount' => $totalNetValuationChange,
                        'discrepancy_count' => $lines->where('discrepancy_quantity', '!=', 0)->count(),
                    ],
                    tenantId: (string) ($adjustment->tenant_id ?? 'default'),
                    companyId: (string) ($adjustment->company_id ?? 'default'),
                    initiatorUserId: $userId
                ));
            } else {
                // Post double-entry stock movements immediately
                $this->postAdjustmentMovements($session, $lines, $userId);
            }

            $session->update([
                'status' => 'reconciled',
                'completed_at' => Carbon::now(),
            ]);

            $event = new InventoryCountCompletedEvent(
                sessionNumber: $session->session_number,
                warehouseId: $session->warehouse_id,
                totalValuationChange: $totalNetValuationChange,
                status: $session->status,
                tenantId: $session->tenant_id,
                companyId: (string) ($session->company_id ?? ''),
                sessionId: (string) $session->id
            );
            $this->dispatcher->publish($event);

            return $adjustment;
        });
    }

    /**
     * @param  iterable<InventoryCountLine>  $lines
     */
    private function postAdjustmentMovements(InventoryCountSession $session, iterable $lines, string $userId): void
    {
        foreach ($lines as $line) {
            $delta = (float) $line->discrepancy_quantity;
            if ($delta === 0.0) {
                continue;
            }

            if ($delta > 0.0) {
                // Found stock: Virtual -> Bin
                $this->movementService->executeTransfer(new StockTransferDTO(
                    stockItemId: $line->stock_item_id,
                    sourceBinId: null,
                    destinationBinId: $line->warehouse_bin_id,
                    quantity: $delta,
                    movementType: 'adjustment',
                    lotId: $line->lot_id,
                    originDocumentType: InventoryCountSession::class,
                    originDocumentId: $session->id,
                    userId: $userId,
                    notes: "Cycle count variance surplus for session {$session->session_number}"
                ));
            } else {
                // Lost stock: Bin -> Virtual Scrap
                $this->movementService->executeTransfer(new StockTransferDTO(
                    stockItemId: $line->stock_item_id,
                    sourceBinId: $line->warehouse_bin_id,
                    destinationBinId: null,
                    quantity: abs($delta),
                    movementType: 'scrap_loss',
                    lotId: $line->lot_id,
                    originDocumentType: InventoryCountSession::class,
                    originDocumentId: $session->id,
                    userId: $userId,
                    notes: "Cycle count variance deficit for session {$session->session_number}"
                ));
            }
        }
    }
}
