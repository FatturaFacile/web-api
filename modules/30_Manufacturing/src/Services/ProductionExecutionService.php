<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Events\FinishedGoodsProducedEvent;
use Modules\Manufacturing\Events\InventoryProducedEvent;
use Modules\Manufacturing\Events\MaterialConsumedEvent;
use Modules\Manufacturing\Events\ProductionOrderCompletedEvent;
use Modules\Manufacturing\Events\ProductionOrderReleasedEvent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgRouting;

final readonly class ProductionExecutionService implements ProductionExecutionServiceInterface
{
    public function __construct(
        private BillOfMaterialsServiceInterface $bomService,
        private ProductionCostingServiceInterface $costingService,
        private EventBusInterface $eventBus,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function releaseOrder(string $productionOrderId): MfgProductionOrder
    {
        return DB::transaction(function () use ($productionOrderId): MfgProductionOrder {
            /** @var MfgProductionOrder $order */
            $order = MfgProductionOrder::lockForUpdate()->findOrFail($productionOrderId);
            $order->transitionTo('released');
            $order->lock_version += 1;
            $order->save();

            // Explode BOM and allocate lines
            if ($order->bom_id && $order->lines()->count() === 0) {
                $components = $this->bomService->explodeBom($order->bom_id, (float) $order->target_quantity);
                foreach ($components as $c) {
                    $item = MfgItem::find($c['component_item_id']);
                    $unitCost = $item ? (float) $item->standard_cost : 10.0;
                    MfgProductionOrderLine::create([
                        'tenant_id' => $order->tenant_id,
                        'production_order_id' => $order->id,
                        'mfg_item_id' => $c['component_item_id'],
                        'required_quantity' => $c['effective_quantity'],
                        'allocated_quantity' => $c['effective_quantity'],
                        'consumed_quantity' => 0.0,
                        'scrapped_quantity' => 0.0,
                        'uom' => $c['uom'],
                        'unit_cost' => $unitCost,
                        'total_cost' => round($c['effective_quantity'] * $unitCost, 4),
                    ]);
                }
            }

            // Generate routing operations if not already present
            if ($order->routing_id && $order->operations()->count() === 0) {
                $routing = MfgRouting::with('operations')->find($order->routing_id);
                if ($routing) {
                    foreach ($routing->operations as $op) {
                        MfgProductionOrderOperation::create([
                            'tenant_id' => $order->tenant_id,
                            'production_order_id' => $order->id,
                            'operation_id' => $op->id,
                            'sequence' => $op->sequence,
                            'work_center_id' => $op->work_center_id,
                            'machine_center_id' => $op->machine_center_id,
                            'status' => 'pending',
                            'planned_setup_minutes' => $op->setup_time_minutes,
                            'planned_run_minutes' => round((float) $op->run_time_minutes_per_unit * (float) $order->target_quantity, 2),
                        ]);
                    }
                }
            }

            $costRollup = $this->costingService->calculateStandardCostRollup($order->id);
            $order->update(['standard_cost_total' => $costRollup['total_standard_cost']]);

            $this->auditLedger->record(
                'mfg.production_order.released',
                $order,
                null,
                ['order_number' => $order->order_number, 'standard_cost' => $costRollup['total_standard_cost']],
                '30_Manufacturing'
            );

            $this->eventBus->dispatch(new ProductionOrderReleasedEvent(
                productionOrderId: $order->id,
                orderNumber: $order->order_number,
                mfgItemId: $order->mfg_item_id,
                targetQuantity: (float) $order->target_quantity,
                estimatedStandardCost: $costRollup['total_standard_cost'],
                tenantId: $order->tenant_id
            ));

            return $order->load(['lines', 'operations']);
        });
    }

    public function startOrder(string $productionOrderId): MfgProductionOrder
    {
        return DB::transaction(function () use ($productionOrderId): MfgProductionOrder {
            $order = MfgProductionOrder::lockForUpdate()->findOrFail($productionOrderId);
            if ($order->status === 'released' || $order->status === 'scheduled') {
                $order->transitionTo('in_progress');
                $order->save();
            }

            $this->auditLedger->record('mfg.production_order.started', $order, null, ['started_at' => $order->actual_start_date], '30_Manufacturing');

            return $order;
        });
    }

    public function recordPartialCompletion(string $productionOrderId, float $completedQuantity, float $scrappedQuantity): MfgProductionOrder
    {
        return DB::transaction(function () use ($productionOrderId, $completedQuantity, $scrappedQuantity): MfgProductionOrder {
            $order = MfgProductionOrder::lockForUpdate()->findOrFail($productionOrderId);
            $order->completed_quantity = (float) $order->completed_quantity + $completedQuantity;
            $order->scrapped_quantity = (float) $order->scrapped_quantity + $scrappedQuantity;
            $order->lock_version += 1;
            $order->save();

            // Consume proportional materials for partial completion
            $ratio = (float) $order->target_quantity > 0 ? ($completedQuantity / (float) $order->target_quantity) : 1.0;
            foreach ($order->lines as $line) {
                $consumedPortion = round((float) $line->required_quantity * $ratio, 4);
                $line->consumed_quantity = (float) $line->consumed_quantity + $consumedPortion;
                $line->save();

                $this->eventBus->dispatch(new MaterialConsumedEvent(
                    productionOrderId: $order->id,
                    componentItemId: $line->mfg_item_id,
                    quantityConsumed: $consumedPortion,
                    unitCost: (float) $line->unit_cost,
                    totalCost: round($consumedPortion * (float) $line->unit_cost, 4),
                    tenantId: $order->tenant_id
                ));
            }

            $this->auditLedger->record(
                'mfg.production_order.partial_completed',
                $order,
                null,
                ['completed' => $completedQuantity, 'scrapped' => $scrappedQuantity],
                '30_Manufacturing'
            );

            return $order;
        });
    }

    public function completeOrder(string $productionOrderId): MfgProductionOrder
    {
        return DB::transaction(function () use ($productionOrderId): MfgProductionOrder {
            $order = MfgProductionOrder::lockForUpdate()->findOrFail($productionOrderId);
            if ($order->completed_quantity <= 0.0) {
                $order->completed_quantity = $order->target_quantity;
            }

            $order->transitionTo('completed');
            $order->lock_version += 1;
            $order->save();

            // Finalize actual costs and compute variances
            $costResults = $this->costingService->calculateActualCostAndVariance($order->id);
            $order->update([
                'actual_cost_total' => $costResults['actual_total_cost'],
                'variance_total' => $costResults['variance_total'],
            ]);

            $this->costingService->postProductionCompletionAccounting($order->id);

            $this->auditLedger->record(
                'mfg.production_order.completed',
                $order,
                null,
                ['completed_quantity' => $order->completed_quantity, 'actual_cost' => $costResults['actual_total_cost']],
                '30_Manufacturing'
            );

            $this->eventBus->dispatch(new ProductionOrderCompletedEvent(
                productionOrderId: $order->id,
                orderNumber: $order->order_number,
                mfgItemId: $order->mfg_item_id,
                completedQuantity: (float) $order->completed_quantity,
                scrappedQuantity: (float) $order->scrapped_quantity,
                actualCostTotal: $costResults['actual_total_cost'],
                varianceTotal: $costResults['variance_total'],
                tenantId: $order->tenant_id
            ));

            $unitCost = (float) $order->completed_quantity > 0 ? round($costResults['actual_total_cost'] / (float) $order->completed_quantity, 4) : 0.0;

            $this->eventBus->dispatch(new FinishedGoodsProducedEvent(
                productionOrderId: $order->id,
                mfgItemId: $order->mfg_item_id,
                quantityProduced: (float) $order->completed_quantity,
                unitCost: $unitCost,
                totalCost: $costResults['actual_total_cost'],
                tenantId: $order->tenant_id
            ));

            $mfgItem = $order->mfgItem;
            if ($mfgItem && $mfgItem->stock_item_id) {
                $this->eventBus->dispatch(new InventoryProducedEvent(
                    productionOrderId: $order->id,
                    stockItemId: $mfgItem->stock_item_id,
                    quantity: (float) $order->completed_quantity,
                    unitCost: $unitCost,
                    tenantId: $order->tenant_id
                ));
            }

            return $order;
        });
    }

    public function closeOrder(string $productionOrderId): MfgProductionOrder
    {
        return DB::transaction(function () use ($productionOrderId): MfgProductionOrder {
            $order = MfgProductionOrder::lockForUpdate()->findOrFail($productionOrderId);
            $order->transitionTo('closed');
            $order->save();

            $this->auditLedger->record('mfg.production_order.closed', $order, null, ['closed_at' => now()], '30_Manufacturing');

            return $order;
        });
    }

    public function cancelOrder(string $productionOrderId, string $reason): MfgProductionOrder
    {
        return DB::transaction(function () use ($productionOrderId, $reason): MfgProductionOrder {
            $order = MfgProductionOrder::lockForUpdate()->findOrFail($productionOrderId);
            $order->transitionTo('cancelled');
            $order->notes = trim(($order->notes ? $order->notes."\n" : '')."Cancelled: {$reason}");
            $order->save();

            // Release allocations
            $order->lines()->update(['allocated_quantity' => 0.0]);

            $this->auditLedger->record('mfg.production_order.cancelled', $order, null, ['reason' => $reason], '30_Manufacturing');

            return $order;
        });
    }
}
