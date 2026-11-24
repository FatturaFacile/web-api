<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Services\LotTraceabilityService;
use Modules\Manufacturing\Models\MfgMachineStateEvent;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

/**
 * Forward and backward genealogy of a production order: the output lots traced back to the supplier lots of their
 * components (inventory lot links), the deliveries that shipped them, and the machines, operators and machine state
 * events of the operations that made them.
 */
final readonly class ProductionGenealogyService
{
    public function __construct(private LotTraceabilityService $traceability) {}

    /**
     * @return array<string, mixed>
     */
    public function forOrder(MfgProductionOrder $order): array
    {
        $outputLots = [];
        $lotIds = MfgMaterialMovement::query()->where('tenant_id', $order->tenant_id)->where('production_order_id', $order->id)
            ->where('movement_type', MfgMaterialMovement::Receipt)->whereNotNull('lot_id')->pluck('lot_id')->unique();

        foreach (StockLot::query()->where('tenant_id', $order->tenant_id)->whereIn('id', $lotIds)->get() as $lot) {
            $outputLots[] = [
                'lot_id' => (string) $lot->id,
                'lot_number' => $lot->lot_number,
                'backward' => $this->traceability->backward($lot),
                'forward' => $this->traceability->forward($lot),
            ];
        }

        $executions = MfgShopFloorExecution::query()->where('tenant_id', $order->tenant_id)->where('production_order_id', $order->id)->get();
        $operationIds = $executions->pluck('production_order_operation_id')->all();

        return [
            'production_order_id' => (string) $order->id,
            'order_number' => $order->order_number,
            'output_lots' => $outputLots,
            'operations' => $executions->map(static fn (MfgShopFloorExecution $e): array => [
                'production_order_operation_id' => (string) $e->production_order_operation_id,
                'machine_id' => $e->machine_id,
                'operator_id' => $e->operator_id,
                'started_at' => $e->started_at->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
            ])->all(),
            'machine_events' => MfgMachineStateEvent::query()->where('tenant_id', $order->tenant_id)->whereIn('production_order_operation_id', $operationIds)->orderBy('occurred_at')
                ->get()->map(static fn (MfgMachineStateEvent $e): array => ['machine_id' => (string) $e->machine_id, 'state' => $e->state, 'occurred_at' => $e->occurred_at->toIso8601String(), 'payload' => $e->payload])->all(),
        ];
    }
}
