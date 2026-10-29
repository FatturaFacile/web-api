<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Events\ProductionCompletedEvent;
use Modules\Manufacturing\Events\ProductionVarianceReadyEvent;
use Modules\Manufacturing\Events\WIPRecognizedEvent;
use Modules\Manufacturing\Models\MfgLaborTimeLog;
use Modules\Manufacturing\Models\MfgMachineLog;
use Modules\Manufacturing\Models\MfgProductionCost;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

final readonly class ProductionCostingService implements ProductionCostingServiceInterface
{
    public function __construct(
        private EventBusInterface $eventBus,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function calculateStandardCostRollup(string $productionOrderId): array
    {
        $order = MfgProductionOrder::with(['lines.mfgItem', 'operations.workCenter'])->findOrFail($productionOrderId);

        // 1. Material Standard Cost
        $materialCost = 0.0;
        foreach ($order->lines as $line) {
            $unitCost = (float) $line->unit_cost > 0 ? (float) $line->unit_cost : (float) ($line->mfgItem?->standard_cost ?? 10.0);
            $materialCost += (float) $line->required_quantity * $unitCost;
        }

        // 2. Labor, Machine, and Overhead Standard Cost from Operations
        $laborCost = 0.0;
        $machineCost = 0.0;
        $overheadCost = 0.0;

        foreach ($order->operations as $op) {
            $runMinutes = (float) $op->planned_run_minutes;
            $setupMinutes = (float) $op->planned_setup_minutes;
            $hours = ($runMinutes + $setupMinutes) / 60.0;

            $laborRate = 25.0000;
            $machineRate = 45.0000;
            $overheadRate = (float) ($op->workCenter?->hourly_overhead_rate ?? 15.0);

            $laborCost += $hours * $laborRate;
            $machineCost += $hours * $machineRate;
            $overheadCost += $hours * $overheadRate;
        }

        $totalStandard = round($materialCost + $laborCost + $machineCost + $overheadCost, 4);

        return [
            'material_cost' => round($materialCost, 4),
            'labor_cost' => round($laborCost, 4),
            'machine_cost' => round($machineCost, 4),
            'overhead_cost' => round($overheadCost, 4),
            'total_standard_cost' => $totalStandard,
        ];
    }

    public function calculateActualCostAndVariance(string $productionOrderId): array
    {
        $order = MfgProductionOrder::with(['lines'])->findOrFail($productionOrderId);

        // Actual material cost from consumed quantities
        $actualMaterial = 0.0;
        foreach ($order->lines as $line) {
            $qty = (float) $line->consumed_quantity > 0 ? (float) $line->consumed_quantity : (float) $line->required_quantity;
            $unitCost = (float) $line->unit_cost > 0 ? (float) $line->unit_cost : 10.0;
            $actualMaterial += $qty * $unitCost;
        }

        // Actual labor and machine costs from execution logs
        $executionIds = MfgShopFloorExecution::where('production_order_id', $productionOrderId)->pluck('id');

        $actualLabor = (float) MfgLaborTimeLog::whereIn('execution_id', $executionIds)->sum('labor_cost');
        if ($actualLabor <= 0.0) {
            // Fallback estimation based on standard if no shop-floor tickets were submitted
            $rollup = $this->calculateStandardCostRollup($productionOrderId);
            $actualLabor = $rollup['labor_cost'];
        }

        $actualMachine = (float) MfgMachineLog::whereIn('execution_id', $executionIds)->sum('machine_cost');
        if ($actualMachine <= 0.0) {
            $rollup = $rollup ?? $this->calculateStandardCostRollup($productionOrderId);
            $actualMachine = $rollup['machine_cost'];
        }

        $actualOverhead = round($actualMachine * 0.35, 4);
        $actualTotal = round($actualMaterial + $actualLabor + $actualMachine + $actualOverhead, 4);

        $standardTotal = (float) $order->standard_cost_total > 0 ? (float) $order->standard_cost_total : $actualTotal;
        $variance = round($actualTotal - $standardTotal, 4);
        $varianceType = ($variance <= 0.0) ? 'favorable' : 'unfavorable';

        return [
            'actual_material_cost' => round($actualMaterial, 4),
            'actual_labor_cost' => round($actualLabor, 4),
            'actual_machine_cost' => round($actualMachine, 4),
            'actual_overhead_cost' => round($actualOverhead, 4),
            'actual_total_cost' => $actualTotal,
            'variance_total' => $variance,
            'variance_type' => $varianceType,
        ];
    }

    public function postProductionWipAccounting(string $productionOrderId): void
    {
        DB::transaction(function () use ($productionOrderId): void {
            $order = MfgProductionOrder::findOrFail($productionOrderId);
            $costs = $this->calculateActualCostAndVariance($productionOrderId);

            $costTypes = [
                'material' => $costs['actual_material_cost'],
                'labor' => $costs['actual_labor_cost'],
                'machine' => $costs['actual_machine_cost'],
                'overhead' => $costs['actual_overhead_cost'],
            ];

            foreach ($costTypes as $type => $amount) {
                MfgProductionCost::updateOrCreate(
                    [
                        'tenant_id' => $order->tenant_id,
                        'production_order_id' => $order->id,
                        'cost_type' => $type,
                    ],
                    [
                        'standard_cost' => round($amount * 0.95, 4),
                        'actual_cost' => $amount,
                        'variance' => round($amount * 0.05, 4),
                        'is_posted_to_gl' => true,
                    ]
                );

                $this->eventBus->dispatch(new WIPRecognizedEvent(
                    productionOrderId: $order->id,
                    costType: $type,
                    amount: $amount,
                    currency: 'EUR',
                    tenantId: $order->tenant_id
                ));
            }
        });
    }

    public function postProductionCompletionAccounting(string $productionOrderId): void
    {
        DB::transaction(function () use ($productionOrderId): void {
            $order = MfgProductionOrder::findOrFail($productionOrderId);
            $costs = $this->calculateActualCostAndVariance($productionOrderId);

            $this->eventBus->dispatch(new ProductionCompletedEvent(
                productionOrderId: $order->id,
                mfgItemId: $order->mfg_item_id,
                quantity: (float) $order->completed_quantity,
                finishedGoodsCost: $costs['actual_total_cost'],
                tenantId: $order->tenant_id
            ));

            $this->eventBus->dispatch(new ProductionVarianceReadyEvent(
                productionOrderId: $order->id,
                standardCost: (float) $order->standard_cost_total,
                actualCost: $costs['actual_total_cost'],
                variance: $costs['variance_total'],
                varianceType: $costs['variance_type'],
                tenantId: $order->tenant_id
            ));
        });
    }
}
