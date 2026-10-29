<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Events\MRPRunCompletedEvent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpPegging;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;
use Modules\Manufacturing\Models\MfgProductionOrder;

final readonly class MrpService implements MrpServiceInterface
{
    public function __construct(
        private BillOfMaterialsServiceInterface $bomService,
        private EventBusInterface $eventBus,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function executeMrpRun(RunMrpDTO $dto): MfgMrpRun
    {
        return DB::transaction(function () use ($dto): MfgMrpRun {
            $runNumber = 'MRP-'.strtoupper((string) Str::random(8));

            $mrpRun = MfgMrpRun::create([
                'tenant_id' => $dto->tenantId,
                'run_number' => $runNumber,
                'run_type' => $dto->runType,
                'status' => 'running',
                'horizon_start_date' => $dto->horizonStartDate,
                'horizon_end_date' => $dto->horizonEndDate,
                'executed_by' => $dto->executedBy,
                'executed_at' => now(),
                'parameters' => $dto->parameters,
            ]);

            $query = MfgItem::where('tenant_id', $dto->tenantId)->where('is_active', true);
            if (! empty($dto->itemIds)) {
                $query->whereIn('id', $dto->itemIds);
            }
            $items = $query->get();

            $totalPlannedOrders = 0;
            $totalShortages = 0;

            foreach ($items as $item) {
                // Gross Demand: existing open production orders + safety stock buffer
                $openOrdersDemand = (float) MfgProductionOrder::where('tenant_id', $dto->tenantId)
                    ->where('mfg_item_id', $item->id)
                    ->whereIn('status', ['planned', 'released', 'scheduled', 'in_progress'])
                    ->sum('target_quantity');

                $grossDemand = $openOrdersDemand;
                $safetyStock = (float) $item->safety_stock;

                // Available inventory: from linked stock item or item on hand
                $stockItem = $item->stockItem;
                $availableInventory = $stockItem ? (float) $stockItem->total_on_hand : 0.0;

                // Scheduled receipts: existing planned orders
                $scheduledReceipts = (float) MfgMrpPlannedOrder::where('tenant_id', $dto->tenantId)
                    ->where('mfg_item_id', $item->id)
                    ->whereIn('status', ['planned', 'firmed'])
                    ->sum('quantity');

                // Canonical MRP Formula:
                // Net Requirement = Gross Demand - Available Inventory - Scheduled Receipts + Safety Stock
                $netRequirement = $grossDemand - $availableInventory - $scheduledReceipts + $safetyStock;

                if ($netRequirement > 0) {
                    $orderQty = max($netRequirement, (float) $item->min_lot_size);
                    $leadTimeDays = max(1, $item->lead_time_days);
                    $dueDate = Carbon::parse($dto->horizonStartDate)->addDays($leadTimeDays * 2);
                    $startDate = Carbon::parse($dueDate)->subDays($leadTimeDays);

                    $orderType = ($item->isRawMaterial()) ? 'buy' : 'make';

                    $plannedOrder = MfgMrpPlannedOrder::create([
                        'tenant_id' => $dto->tenantId,
                        'planned_order_number' => 'PLN-'.strtoupper((string) Str::random(8)),
                        'mrp_run_id' => $mrpRun->id,
                        'mfg_item_id' => $item->id,
                        'order_type' => $orderType,
                        'quantity' => $orderQty,
                        'uom' => $item->uom,
                        'start_date' => $startDate->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                        'status' => 'planned',
                        'bom_id' => $item->default_bom_id,
                        'routing_id' => $item->default_routing_id,
                    ]);

                    MfgMrpPegging::create([
                        'tenant_id' => $dto->tenantId,
                        'planned_order_id' => $plannedOrder->id,
                        'demand_type' => 'safety_stock',
                        'demand_reference_id' => null,
                        'pegged_quantity' => $orderQty,
                    ]);

                    MfgMrpShortage::create([
                        'tenant_id' => $dto->tenantId,
                        'mrp_run_id' => $mrpRun->id,
                        'mfg_item_id' => $item->id,
                        'required_date' => $dueDate->toDateString(),
                        'gross_requirement' => $grossDemand,
                        'available_inventory' => $availableInventory,
                        'scheduled_receipts' => $scheduledReceipts,
                        'safety_stock' => $safetyStock,
                        'net_shortage' => $netRequirement,
                        'recommended_action' => $orderType === 'buy' ? 'purchase_order' : 'make_order',
                    ]);

                    $totalPlannedOrders++;
                    $totalShortages++;

                    // Multi-level demand explosion for manufactured items
                    if ($orderType === 'make' && $item->default_bom_id) {
                        $components = $this->bomService->explodeBom($item->default_bom_id, $orderQty);
                        foreach ($components as $child) {
                            MfgMrpShortage::create([
                                'tenant_id' => $dto->tenantId,
                                'mrp_run_id' => $mrpRun->id,
                                'mfg_item_id' => $child['component_item_id'],
                                'required_date' => $startDate->toDateString(),
                                'gross_requirement' => $child['effective_quantity'],
                                'available_inventory' => 0.0,
                                'scheduled_receipts' => 0.0,
                                'safety_stock' => 0.0,
                                'net_shortage' => $child['effective_quantity'],
                                'recommended_action' => 'purchase_order',
                            ]);
                            $totalShortages++;
                        }
                    }
                }
            }

            $mrpRun->update([
                'status' => 'completed',
                'total_items_processed' => $items->count(),
                'total_planned_orders' => $totalPlannedOrders,
                'total_shortages' => $totalShortages,
            ]);

            $this->auditLedger->record(
                'mfg.mrp.completed',
                $mrpRun,
                null,
                ['run_number' => $mrpRun->run_number, 'planned_orders' => $totalPlannedOrders],
                '30_Manufacturing'
            );

            $this->eventBus->dispatch(new MRPRunCompletedEvent(
                mrpRunId: $mrpRun->id,
                runNumber: $mrpRun->run_number,
                runType: $mrpRun->run_type,
                totalPlannedOrders: $totalPlannedOrders,
                totalShortages: $totalShortages,
                tenantId: $dto->tenantId
            ));

            return $mrpRun->load(['plannedOrders', 'shortages']);
        });
    }

    public function firmPlannedOrder(string $plannedOrderId): void
    {
        $order = MfgMrpPlannedOrder::findOrFail($plannedOrderId);
        $order->update(['status' => 'firmed']);
        $this->auditLedger->record('mfg.planned_order.firmed', $order, null, ['planned_order_number' => $order->planned_order_number], '30_Manufacturing');
    }

    public function convertPlannedOrder(string $plannedOrderId): string
    {
        return DB::transaction(function () use ($plannedOrderId): string {
            $order = MfgMrpPlannedOrder::findOrFail($plannedOrderId);

            if ($order->isMake()) {
                $prodOrder = MfgProductionOrder::create([
                    'tenant_id' => $order->tenant_id,
                    'order_number' => 'MO-'.strtoupper((string) Str::random(8)),
                    'mfg_item_id' => $order->mfg_item_id,
                    'bom_id' => $order->bom_id ?? MfgItem::find($order->mfg_item_id)?->default_bom_id,
                    'routing_id' => $order->routing_id ?? MfgItem::find($order->mfg_item_id)?->default_routing_id,
                    'status' => 'planned',
                    'target_quantity' => $order->quantity,
                    'uom' => $order->uom,
                    'planned_start_date' => $order->start_date,
                    'planned_end_date' => $order->due_date,
                ]);

                $order->update([
                    'status' => 'released',
                    'converted_order_id' => $prodOrder->id,
                ]);

                return $prodOrder->id;
            }

            // Buy conversion -> returns converted order marker
            $order->update(['status' => 'released']);

            return (string) Str::uuid7();
        });
    }
}
