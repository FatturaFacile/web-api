<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\InventoryAdjustmentDTO;
use Modules\Inventory\Events\StockAdjustedEvent;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\InventoryAdjustmentPoster;
use Modules\Inventory\Support\Quantity;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

/**
 * Records a manual inventory adjustment (found stock, damage, obsolete write-off, scrap). Within the approval
 * threshold (`inventory.adjustment_approval_threshold`, on the absolute net value) it is posted at once: stock moves
 * and the cost impact reaches the ledger. Above it, it waits for the INVENTORY_WRITE_OFF_APPROVAL workflow with its
 * lines in the workflow context, and is posted on approval (PostInventoryAdjustmentAction). The number comes from the
 * gapless document numbering.
 */
final readonly class CreateInventoryAdjustmentAction
{
    public function __construct(
        private InventoryAdjustmentPoster $poster,
        private WorkflowEngineInterface $workflowEngine,
        private EventBusInterface $eventBus,
        private DocumentNumberingService $numbering,
        private TenantContext $tenantContext
    ) {}

    public function execute(InventoryAdjustmentDTO $dto): InventoryAdjustment
    {
        return DB::transaction(function () use ($dto): InventoryAdjustment {
            $this->poster->assertBinsOfWarehouse($dto->warehouseId, $dto->lines);

            $totalValuationChange = $this->poster->netValue($dto->lines);
            $requiresApproval = Quantity::compare(Quantity::abs($totalValuationChange, 2), (string) config('inventory.adjustment_approval_threshold')) > 0;
            $tenantId = (string) ($this->tenantContext->getTenantId() ?? Warehouse::query()->findOrFail($dto->warehouseId)->tenant_id);

            $adjustment = InventoryAdjustment::create([
                'warehouse_id' => $dto->warehouseId,
                'adjustment_number' => $this->numbering->next('inventory_adjustment', null, $tenantId)['number'],
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
                        'amount' => Quantity::abs($totalValuationChange, 2),
                        'reason' => $dto->reason,
                        'warehouse_id' => $dto->warehouseId,
                        'adjustment_id' => $adjustment->id,
                        'lines' => $dto->lines,
                    ],
                    tenantId: (string) $adjustment->tenant_id,
                    companyId: $adjustment->company_id !== null ? (string) $adjustment->company_id : null,
                    initiatorUserId: $dto->userId
                ));
            } else {
                $this->poster->post($adjustment, $dto->lines, $dto->userId);
            }

            $this->eventBus->dispatch(new StockAdjustedEvent(
                adjustmentId: (string) $adjustment->id,
                warehouseId: $dto->warehouseId,
                netValuationChange: (float) $totalValuationChange,
                status: (string) $adjustment->status,
                companyId: (string) ($adjustment->company_id ?? 'default'),
                userId: $dto->userId,
                tenantId: (string) $adjustment->tenant_id
            ));

            return $adjustment;
        });
    }
}
