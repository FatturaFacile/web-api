<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Events\ProductionOrderCreatedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Support\Money;

/**
 * Plans production orders: the item must be manufactured, the BOM must be a released revision of the item (the one
 * effective on the planned start date by default), the routing an active routing of the item.
 */
final readonly class ProductionPlanningService implements ProductionPlanningServiceInterface
{
    public function __construct(
        private BillOfMaterialsServiceInterface $boms,
        private RoutingServiceInterface $routings,
        private EventDispatcher $dispatcher,
    ) {}

    public function planOrder(CreateProductionOrderDTO $dto): MfgProductionOrder
    {
        return DB::transaction(function () use ($dto): MfgProductionOrder {
            $item = MfgItem::query()->where('tenant_id', $dto->tenantId)->findOrFail($dto->mfgItemId);
            if ($item->procurementType() === 'buy') {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is purchased, not manufactured.", 'mfg_item_id');
            }

            $quantity = Money::qty($dto->targetQuantity);
            if (! Money::isPositive($quantity)) {
                throw new ManufacturingOperationException('The quantity to produce must be greater than zero.', 'target_quantity');
            }

            $bom = $dto->bomId !== null
                ? MfgBom::query()->where('tenant_id', $dto->tenantId)->where('mfg_item_id', $item->id)->find($dto->bomId)
                : $this->boms->effectiveBomFor($item, $dto->plannedStartDate);
            if (! $bom instanceof MfgBom || $bom->isDraft()) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] has no released BOM revision for the planned start date.", 'bom_id');
            }

            $routing = $dto->routingId !== null
                ? MfgRouting::query()->where('tenant_id', $dto->tenantId)->where('mfg_item_id', $item->id)->where('status', 'active')->find($dto->routingId)
                : $this->routings->effectiveRoutingFor($item, $dto->plannedStartDate);
            if ($dto->routingId !== null && ! $routing instanceof MfgRouting) {
                throw new ManufacturingOperationException('The routing is not an active routing of the item.', 'routing_id');
            }

            $order = MfgProductionOrder::create([
                'tenant_id' => $dto->tenantId,
                'order_number' => 'MO-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'mfg_item_id' => $item->id,
                'bom_id' => $bom->id,
                'routing_id' => $routing?->id,
                'project_id' => $dto->projectId,
                'sales_order_id' => $dto->salesOrderId,
                'planned_order_id' => $dto->plannedOrderId,
                'warehouse_id' => $dto->warehouseId ?? $item->default_warehouse_id,
                'receipt_warehouse_id' => $dto->receiptWarehouseId ?? $dto->warehouseId ?? $item->default_warehouse_id,
                'status' => 'planned',
                'target_quantity' => $quantity,
                'uom' => $item->uom,
                'planned_start_date' => $dto->plannedStartDate,
                'planned_end_date' => $dto->plannedEndDate,
                'notes' => $dto->notes,
                'created_by' => $dto->createdBy !== null && Str::isUuid($dto->createdBy) ? $dto->createdBy : null,
            ]);

            $this->dispatcher->publishAndDispatch(new ProductionOrderCreatedEvent(
                productionOrderId: (string) $order->id,
                orderNumber: $order->order_number,
                mfgItemId: (string) $item->id,
                targetQuantity: Money::qtyToFloat($quantity),
                plannedStartDate: $dto->plannedStartDate,
                plannedEndDate: $dto->plannedEndDate,
                tenantId: $dto->tenantId
            ));

            return $order->refresh();
        });
    }

    public function scheduleOrder(MfgProductionOrder $order, string $startDate, string $endDate): MfgProductionOrder
    {
        return DB::transaction(function () use ($order, $startDate, $endDate): MfgProductionOrder {
            $locked = MfgProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['planned', 'released'], true)) {
                throw new ManufacturingOperationException("Production order [{$locked->order_number}] is {$locked->status} and cannot be rescheduled.", 'status');
            }

            $locked->update(['planned_start_date' => $startDate, 'planned_end_date' => $endDate]);

            return $locked->refresh();
        });
    }
}
