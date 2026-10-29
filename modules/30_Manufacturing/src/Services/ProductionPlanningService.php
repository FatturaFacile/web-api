<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Events\ProductionOrderCreatedEvent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;

final readonly class ProductionPlanningService implements ProductionPlanningServiceInterface
{
    public function __construct(
        private EventBusInterface $eventBus,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function planOrder(CreateProductionOrderDTO $dto): MfgProductionOrder
    {
        return DB::transaction(function () use ($dto): MfgProductionOrder {
            $item = MfgItem::where('id', $dto->mfgItemId)->where('tenant_id', $dto->tenantId)->firstOrFail();
            $bomId = $dto->bomId ?? $item->default_bom_id;
            $routingId = $dto->routingId ?? $item->default_routing_id;

            if ($bomId === null) {
                throw new InvalidArgumentException("Manufacturing item [{$item->item_code}] requires a Bill of Materials.");
            }

            $orderNumber = 'MO-'.strtoupper((string) Str::random(8));

            $order = MfgProductionOrder::create([
                'tenant_id' => $dto->tenantId,
                'order_number' => $orderNumber,
                'mfg_item_id' => $dto->mfgItemId,
                'bom_id' => $bomId,
                'routing_id' => $routingId,
                'project_id' => $dto->projectId,
                'status' => 'planned',
                'target_quantity' => $dto->targetQuantity,
                'uom' => $item->uom,
                'planned_start_date' => $dto->plannedStartDate,
                'planned_end_date' => $dto->plannedEndDate,
                'lock_version' => 1,
                'notes' => $dto->notes,
            ]);

            $this->auditLedger->record(
                'mfg.production_order.planned',
                $order,
                null,
                ['order_number' => $order->order_number, 'target_quantity' => $order->target_quantity],
                '30_Manufacturing'
            );

            $this->eventBus->dispatch(new ProductionOrderCreatedEvent(
                productionOrderId: $order->id,
                orderNumber: $order->order_number,
                mfgItemId: $order->mfg_item_id,
                targetQuantity: (float) $order->target_quantity,
                plannedStartDate: $order->planned_start_date->toDateString(),
                plannedEndDate: $order->planned_end_date->toDateString(),
                tenantId: $dto->tenantId
            ));

            return $order;
        });
    }

    public function scheduleOrder(string $productionOrderId, string $startDate, string $endDate): MfgProductionOrder
    {
        return DB::transaction(function () use ($productionOrderId, $startDate, $endDate): MfgProductionOrder {
            $order = MfgProductionOrder::findOrFail($productionOrderId);
            if ($order->status === 'planned') {
                $order->transitionTo('released');
            }
            $order->transitionTo('scheduled');

            $order->update([
                'planned_start_date' => $startDate,
                'planned_end_date' => $endDate,
            ]);

            $this->auditLedger->record(
                'mfg.production_order.scheduled',
                $order,
                null,
                ['start_date' => $startDate, 'end_date' => $endDate],
                '30_Manufacturing'
            );

            return $order;
        });
    }

    public function checkCapacityAvailability(string $workCenterId, string $date, float $requiredHours): bool
    {
        $workCenter = MfgWorkCenter::findOrFail($workCenterId);
        $capacity = MfgWorkCenterCapacity::where('work_center_id', $workCenterId)
            ->where('calendar_date', $date)
            ->first();

        $dailyCapacity = $capacity ? (float) $capacity->available_hours : $workCenter->calculateDailyEffectiveCapacityHours();
        $bookedHours = $capacity ? (float) $capacity->booked_hours : 0.0;

        return ($bookedHours + $requiredHours) <= $dailyCapacity;
    }
}
