<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Events\ReorderPointReachedEvent;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockReorderRule;

final class InventoryReplenishmentService implements InventoryReplenishmentServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher
    ) {}

    public function evaluateReorderRule(StockReorderRule $rule): ?string
    {
        $item = $rule->item;
        if (! $item) {
            return null;
        }

        $availableInWarehouse = (float) $item->quants()
            ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $rule->warehouse_id))
            ->sum(DB::raw('quantity_on_hand - quantity_reserved'));

        if ($availableInWarehouse <= (float) $rule->reorder_point) {
            $event = new ReorderPointReachedEvent(
                reorderRuleId: $rule->id,
                stockItemId: $item->id,
                warehouseId: $rule->warehouse_id,
                currentStock: $availableInWarehouse,
                reorderPoint: (float) $rule->reorder_point,
                suggestedOrderQty: (float) $rule->economic_order_quantity,
                preferredSupplierId: $rule->preferred_supplier_id,
                tenantId: $rule->tenant_id,
                companyId: (string) ($rule->company_id ?? '')
            );

            $this->dispatcher->publish($event);

            return $event->getEventId();
        }

        return null;
    }

    public function calculateSafetyStock(StockItem $item, int $leadTimeDays, float $serviceLevelFactor = 1.65): float
    {
        // 90-day daily standard deviation of consumption
        $dailyConsumptions = StockMovement::query()
            ->where('stock_item_id', $item->id)
            ->whereIn('movement_type', ['customer_delivery', 'job_consumption'])
            ->where('executed_at', '>=', now()->subDays(90))
            ->selectRaw('DATE(executed_at) as date, SUM(quantity) as total_qty')
            ->groupByRaw('DATE(executed_at)')
            ->pluck('total_qty')
            ->map(fn ($q) => (float) $q)
            ->all();

        if (count($dailyConsumptions) < 2) {
            return round(5.0 * sqrt(max(1, $leadTimeDays)), 2);
        }

        $mean = array_sum($dailyConsumptions) / count($dailyConsumptions);
        $variance = 0.0;
        foreach ($dailyConsumptions as $val) {
            $variance += pow($val - $mean, 2);
        }
        $stdDev = sqrt($variance / (count($dailyConsumptions) - 1));

        return round($serviceLevelFactor * $stdDev * sqrt(max(1, $leadTimeDays)), 2);
    }

    public function computeAbcClassification(string $tenantId): array
    {
        // Pareto 80/15/5 analysis of consumption valuation
        $itemsConsumption = StockMovement::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('movement_type', ['customer_delivery', 'job_consumption'])
            ->where('executed_at', '>=', now()->subDays(365))
            ->selectRaw('stock_item_id, SUM(total_valuation) as annual_valuation')
            ->groupBy('stock_item_id')
            ->orderByDesc('annual_valuation')
            ->get();

        $totalValuation = (float) $itemsConsumption->sum('annual_valuation');
        $runningSum = 0.0;
        $classifications = [];

        foreach ($itemsConsumption as $record) {
            $val = (float) $record->annual_valuation;
            $runningSum += $val;
            $percentage = $totalValuation > 0.0 ? ($runningSum / $totalValuation) * 100 : 100;

            $tier = match (true) {
                $percentage <= 80.0 => 'A',
                $percentage <= 95.0 => 'B',
                default => 'C',
            };

            $classifications[$record->stock_item_id] = [
                'tier' => $tier,
                'annual_valuation' => $val,
                'cumulative_percentage' => round($percentage, 2),
            ];
        }

        return $classifications;
    }
}
