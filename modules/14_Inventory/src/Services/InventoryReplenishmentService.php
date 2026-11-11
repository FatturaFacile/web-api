<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Product;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Events\ReorderPointReachedEvent;
use Modules\Inventory\Events\StockBelowMinimumEvent;
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
        private readonly AbcClassificationService $abc,
        private readonly ProductStockLedger $productStock
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
     * Crossing detection for the minimum stock level of a rule (monitored when the level is above zero). The state is
     * kept in below_minimum_since: set when the available stock is found below the minimum (and the event is announced,
     * claimed with an atomic update so concurrent evaluations announce once), cleared when it is back at or above it.
     * Evaluations while it stays below announce nothing.
     */
    public function evaluateMinimumStock(StockReorderRule $rule): ?string
    {
        $item = $rule->item;
        $minimum = (string) $rule->min_stock_level;

        if ($item === null || Quantity::compare($minimum, '0') <= 0) {
            return null;
        }

        $available = $this->availableInWarehouse($item, (string) $rule->warehouse_id);
        $rules = StockReorderRule::withoutGlobalScopes()->whereKey($rule->id);

        if (Quantity::compare($available, $minimum) >= 0) {
            (clone $rules)->whereNotNull('below_minimum_since')->update(['below_minimum_since' => null]);

            return null;
        }

        if ((clone $rules)->whereNull('below_minimum_since')->update(['below_minimum_since' => now()]) !== 1) {
            return null;
        }

        $event = new StockBelowMinimumEvent(
            scope: StockBelowMinimumEvent::ScopeWarehouse,
            reorderRuleId: (string) $rule->id,
            stockItemId: (string) $rule->stock_item_id,
            warehouseId: (string) $rule->warehouse_id,
            productId: $item->product_id !== null ? (string) $item->product_id : null,
            availableStock: (float) $available,
            minimumStock: (float) $minimum,
            tenantId: (string) $rule->tenant_id,
            companyId: (string) ($rule->company_id ?? '')
        );

        $this->dispatcher->publishAndDispatch($event);

        return $event->getEventId();
    }

    /**
     * Crossing detection for the minimum stock of a catalog product (products.min_stock), on the stock of the product
     * across all warehouses: the figure of the Low stock dashboard widget, ProductStockLedger::levels() "quantity" (on
     * hand net of the quantity sold beyond the stock). A product is monitored while it is active, tracks inventory and
     * has a minimum above zero; otherwise its state is cleared. Same crossing rule as evaluateMinimumStock(): the event
     * is announced when the stock is found below the minimum and products.below_minimum_since is empty, which is then
     * set; it is cleared when the stock is back at or above the minimum.
     *
     * When a reorder rule minimum (per warehouse) and the product minimum both exist they are independent thresholds on
     * different quantities (one warehouse, all warehouses): each has its own crossing and its own event, told apart by
     * the payload "scope". One movement can therefore announce both; a receiver that wants one signal per item filters
     * on scope.
     */
    public function evaluateProductMinimumStock(Product $product): ?string
    {
        $products = Product::withoutGlobalScopes()->whereKey($product->id);
        $minimum = $product->min_stock !== null ? (string) $product->min_stock : '0';

        if (! $product->track_inventory || ! $product->is_active || Quantity::compare($minimum, '0') <= 0) {
            (clone $products)->whereNotNull('below_minimum_since')->update(['below_minimum_since' => null]);

            return null;
        }

        $stock = Quantity::of((string) ($this->productStock->levels(null, [(string) $product->id])->first()['quantity'] ?? 0));

        if (Quantity::compare($stock, $minimum) >= 0) {
            (clone $products)->whereNotNull('below_minimum_since')->update(['below_minimum_since' => null]);

            return null;
        }

        if ((clone $products)->whereNull('below_minimum_since')->update(['below_minimum_since' => now()]) !== 1) {
            return null;
        }

        $event = new StockBelowMinimumEvent(
            scope: StockBelowMinimumEvent::ScopeProduct,
            reorderRuleId: null,
            stockItemId: null,
            warehouseId: null,
            productId: (string) $product->id,
            availableStock: (float) $stock,
            minimumStock: (float) $minimum,
            tenantId: (string) $product->tenant_id,
            companyId: (string) ($product->company_id ?? '')
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

        $available = $this->availableInWarehouse($item, (string) $rule->warehouse_id);

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
     * What the warehouse can still give of the item for replenishment purposes: on hand minus reserved, leaving out the
     * bins of quarantine and transit zones and the quants of quarantined lots (the same stock the allocation planner
     * never hands to an order). Procurement reads it through the interface, so a purchase suggestion and the Inventory
     * replenishment report always agree on the position of a rule.
     */
    public function availableInWarehouse(StockItem $item, string $warehouseId): string
    {
        return Quantity::of((string) $item->quants()
            ->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId)->whereNotIn('zone_type', StockAllocationPlanner::BLOCKED_ZONE_TYPES))
            ->where(static fn ($quant) => $quant->whereNull('lot_id')->orWhereHas('lot', static fn ($lot) => $lot->where('is_quarantined', false)))
            ->sum(DB::raw('quantity_on_hand - quantity_reserved')));
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
