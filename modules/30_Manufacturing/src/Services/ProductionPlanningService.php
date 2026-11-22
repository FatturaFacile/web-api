<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
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
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgWorkCenter;
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
        private WorkCenterService $workCenters,
    ) {}

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
    public function scheduleOrder(MfgProductionOrder $order, string $startDate, string $endDate, bool $allowOverload = false): MfgProductionOrder
    {
        return DB::transaction(function () use ($order, $startDate, $endDate, $allowOverload): MfgProductionOrder {
            $locked = MfgProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['planned', 'released'], true)) {
                throw new ManufacturingOperationException("Production order [{$locked->order_number}] is {$locked->status} and cannot be rescheduled.", 'status');
            }

            $operations = MfgProductionOrderOperation::query()->where('production_order_id', $locked->id)->where('status', '!=', 'completed')->get();
            if (! $allowOverload) {
                $this->assertFitsCapacity($locked, $operations, $startDate, $endDate);
            }

            // The operations of a released order keep their place relative to the order: they move with its start date.
            $shift = (int) CarbonImmutable::parse($locked->planned_start_date)->startOfDay()->diffInDays(CarbonImmutable::parse($startDate)->startOfDay(), false);
            if ($shift !== 0) {
                foreach ($operations as $operation) {
                    if ($operation->scheduled_date !== null) {
                        $operation->update(['scheduled_date' => CarbonImmutable::parse($operation->scheduled_date)->addDays($shift)->toDateString()]);
                    }
                }
            }

            $locked->update(['planned_start_date' => $startDate, 'planned_end_date' => $endDate]);

            return $locked->refresh();
        });
    }

    /**
     * Finite-capacity check of a move: the hours the order needs on each work centre must fit in the free hours of the
     * window (available hours minus the load of the other orders; the order's own booking inside the window is not a
     * conflict). A released order needs its open operations, a planned one the operations of its routing.
     *
     * @param  Collection<int, MfgProductionOrderOperation>  $operations
     */
    private function assertFitsCapacity(MfgProductionOrder $order, Collection $operations, string $startDate, string $endDate): void
    {
        $demand = [];
        $own = [];
        $from = CarbonImmutable::parse($startDate)->toDateString();
        $to = CarbonImmutable::parse($endDate)->toDateString();

        if ($operations->isNotEmpty()) {
            foreach ($operations as $operation) {
                if ($operation->work_center_id === null || $operation->is_subcontracted) {
                    continue;
                }
                $minutes = Money::add($operation->planned_setup_minutes, $operation->planned_run_minutes);
                $center = (string) $operation->work_center_id;
                $demand[$center] = Money::add($demand[$center] ?? '0', $minutes);
                $scheduled = $operation->scheduled_date?->toDateString();
                if ($scheduled !== null && $scheduled >= $from && $scheduled <= $to) {
                    $remaining = Money::max('0', Money::sub($minutes, Money::add($operation->actual_setup_minutes, $operation->actual_run_minutes)));
                    $own[$center] = Money::add($own[$center] ?? '0', $remaining);
                }
            }
        } elseif ($order->routing_id !== null) {
            foreach (MfgOperation::query()->where('routing_id', $order->routing_id)->get() as $operation) {
                if ($operation->work_center_id === null || $operation->is_subcontracted) {
                    continue;
                }
                $center = (string) $operation->work_center_id;
                $demand[$center] = Money::add($demand[$center] ?? '0', $operation->machineMinutesFor((string) $order->target_quantity));
            }
        }

        foreach ($demand as $centerId => $minutes) {
            $center = MfgWorkCenter::query()->where('tenant_id', $order->tenant_id)->find($centerId);
            if (! $center instanceof MfgWorkCenter) {
                continue;
            }
            $days = collect($this->workCenters->capacity($center, $from, $to));
            $available = $days->reduce(static fn (string $sum, array $day): string => Money::add($sum, $day['available_hours']), '0');
            $load = $days->reduce(static fn (string $sum, array $day): string => Money::add($sum, $day['load_hours']), '0');
            $others = Money::max('0', Money::sub($load, Money::div($own[$centerId] ?? '0', '60')));
            $free = Money::max('0', Money::sub($available, $others));
            $needed = Money::div($minutes, '60');

            if (Money::compare($needed, Money::add($free, '0.005')) > 0) {
                throw new ManufacturingOperationException(
                    'Work centre ['.$center->work_center_code.'] is overloaded between '.$from.' and '.$to.': '.Money::round($needed).' h needed, '.Money::round($free).' h free.',
                    'planned_start_date'
                );
            }
        }
    }
}
