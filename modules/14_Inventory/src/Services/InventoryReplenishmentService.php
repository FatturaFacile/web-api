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
use Modules\Inventory\Support\Quantity;

/**
 * Replenishment by reorder rules (min/max with reorder point, per item and warehouse).
 *
 * The available stock of a rule is what its warehouse can still give: on hand minus reserved, in bins outside
 * quarantine and transit zones. At or below the reorder point the rule needs replenishment: the suggested quantity is
 * the economic order quantity, or what brings the stock back up to the maximum level when more is missing. Purchase
 * orders are not created here: Procurement turns the rules into purchase suggestions (PurchaseSuggestionService),
 * adding what is already on order.
 */
final class InventoryReplenishmentService implements InventoryReplenishmentServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AbcClassificationService $abc
    ) {}

    /**
     * Announces ReorderPointReachedEvent when the rule's available stock is at or below its reorder point; returns the
     * event id, or null when no replenishment is needed.
     */
    public function evaluateReorderRule(StockReorderRule $rule): ?string
    {
        $suggestion = $this->suggestionFor($rule);

        if ($suggestion === null || ! $suggestion['needs_replenishment']) {
            return null;
        }

        $event = new ReorderPointReachedEvent(
            reorderRuleId: $rule->id,
            stockItemId: (string) $rule->stock_item_id,
            warehouseId: $rule->warehouse_id,
            currentStock: (float) $suggestion['available'],
            reorderPoint: (float) $suggestion['reorder_point'],
            suggestedOrderQty: (float) $suggestion['suggested_quantity'],
            preferredSupplierId: $rule->preferred_supplier_id,
            tenantId: $rule->tenant_id,
            companyId: (string) ($rule->company_id ?? '')
        );

        $this->dispatcher->publishAndDispatch($event);

        return $event->getEventId();
    }

    /**
     * The replenishment situation of a rule, or null when its item no longer exists.
     *
     * @return array{rule_id: string, stock_item_id: string, sku: string, warehouse_id: string, available: string, min_stock_level: string, reorder_point: string, max_stock_level: string, economic_order_quantity: string, needs_replenishment: bool, suggested_quantity: string, preferred_supplier_id: ?string, lead_time_days: int}|null
     */
    public function suggestionFor(StockReorderRule $rule): ?array
    {
        $item = $rule->item;

        if ($item === null) {
            return null;
        }

        $available = Quantity::of((string) $item->quants()
            ->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $rule->warehouse_id)->whereNotIn('zone_type', StockAllocationPlanner::BLOCKED_ZONE_TYPES))
            ->sum(DB::raw('quantity_on_hand - quantity_reserved')));

        $needs = Quantity::compare($available, (string) $rule->reorder_point) <= 0;
        $quantity = $needs ? Quantity::max((string) $rule->economic_order_quantity, Quantity::sub((string) $rule->max_stock_level, $available)) : Quantity::of(0);

        return [
            'rule_id' => (string) $rule->id,
            'stock_item_id' => (string) $item->id,
            'sku' => (string) $item->sku,
            'warehouse_id' => (string) $rule->warehouse_id,
            'available' => $available,
            'min_stock_level' => Quantity::of((string) $rule->min_stock_level),
            'reorder_point' => Quantity::of((string) $rule->reorder_point),
            'max_stock_level' => Quantity::of((string) $rule->max_stock_level),
            'economic_order_quantity' => Quantity::of((string) $rule->economic_order_quantity),
            'needs_replenishment' => $needs,
            'suggested_quantity' => $quantity,
            'preferred_supplier_id' => $rule->preferred_supplier_id !== null ? (string) $rule->preferred_supplier_id : null,
            'lead_time_days' => (int) $rule->lead_time_days,
        ];
    }

    /**
     * Safety stock = service level factor x standard deviation of the daily consumption of the last 90 days x square root
     * of the lead time. With fewer than two days of consumption the deviation is unknown and 0.0 is returned rather than
     * a made-up figure.
     */
    public function calculateSafetyStock(StockItem $item, int $leadTimeDays, float $serviceLevelFactor = 1.65): float
    {
        $dailyConsumptions = StockMovement::query()
            ->where('stock_item_id', $item->id)
            ->whereNotNull('source_location_id')
            ->whereNull('destination_location_id')
            ->whereNotIn('movement_type', AbcClassificationService::NON_CONSUMPTION_TYPES)
            ->where('executed_at', '>=', now()->subDays(90))
            ->selectRaw('DATE(executed_at) as date, SUM(quantity) as total_qty')
            ->groupByRaw('DATE(executed_at)')
            ->pluck('total_qty')
            ->map(static fn ($quantity): float => (float) $quantity)
            ->all();

        if (count($dailyConsumptions) < 2) {
            return 0.0;
        }

        $mean = array_sum($dailyConsumptions) / count($dailyConsumptions);
        $variance = 0.0;
        foreach ($dailyConsumptions as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return round($serviceLevelFactor * sqrt($variance / (count($dailyConsumptions) - 1)) * sqrt(max(1, $leadTimeDays)), 2);
    }

    /**
     * The ABC classes of the last year of consumption (80/95 split), without storing them; see AbcClassificationService.
     *
     * @return array<string, array{tier: string, annual_valuation: float, cumulative_percentage: float}>
     */
    public function computeAbcClassification(string $tenantId): array
    {
        $classes = [];

        foreach ($this->abc->classify(365, persist: false) as $row) {
            $classes[$row['stock_item_id']] = [
                'tier' => $row['abc_class'],
                'annual_valuation' => (float) $row['consumption_value'],
                'cumulative_percentage' => (float) $row['cumulative_share'],
            ];
        }

        return $classes;
    }
}
