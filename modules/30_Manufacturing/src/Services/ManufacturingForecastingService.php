<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Manufacturing\Contracts\ManufacturingForecastingServiceInterface;
use Modules\Manufacturing\Events\InventoryForecastEvent;
use Modules\Manufacturing\Events\MaterialPurchaseForecastEvent;
use Modules\Manufacturing\Events\ProductionCashForecastEvent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Support\Money;

/**
 * Monthly projection of production for Treasury, valued at standard cost only (no estimated rates):
 *
 * - production cost: the open quantity of open production orders at their frozen standard unit cost (the item standard
 *   for planned orders not yet released) plus open make planned orders at the item standard, by planned end month;
 * - material purchases: open purchase suggestions at the item standard cost, by due month;
 * - inventory inflow: the same make quantities at standard, by due month.
 */
final readonly class ManufacturingForecastingService implements ManufacturingForecastingServiceInterface
{
    public function __construct(private EventDispatcher $dispatcher) {}

    public function generateCashAndMaterialForecast(string $tenantId, int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $base = now()->startOfMonth();
        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $buckets[$base->copy()->addMonths($i)->format('Y-m')] = ['production' => '0', 'purchase' => '0', 'inflow' => '0'];
        }
        $first = $base->toDateString();
        $last = $base->copy()->addMonths($months - 1)->endOfMonth()->toDateString();
        $standards = [];

        $orders = MfgProductionOrder::query()->where('tenant_id', $tenantId)->whereIn('status', ['planned', 'released', 'in_progress'])
            ->whereBetween('planned_end_date', [$first, $last])->get();
        foreach ($orders as $order) {
            $month = $order->planned_end_date->format('Y-m');
            $open = Money::max('0', Money::sub($order->target_quantity, $order->completed_quantity));
            $unit = Money::isPositive($order->standardUnitCost()) ? $order->standardUnitCost() : $this->standard($tenantId, (string) $order->mfg_item_id, $standards);
            $value = Money::mul($open, $unit);
            $buckets[$month]['production'] = Money::add($buckets[$month]['production'], $value);
            $buckets[$month]['inflow'] = Money::add($buckets[$month]['inflow'], $value);
        }

        $planned = MfgMrpPlannedOrder::query()->where('tenant_id', $tenantId)->where('status', 'planned')->whereBetween('due_date', [$first, $last])->get();
        foreach ($planned as $plannedOrder) {
            $month = $plannedOrder->due_date->format('Y-m');
            $value = Money::mul($plannedOrder->quantity, $this->standard($tenantId, (string) $plannedOrder->mfg_item_id, $standards));
            if ($plannedOrder->isBuy()) {
                $buckets[$month]['purchase'] = Money::add($buckets[$month]['purchase'], $value);

                continue;
            }
            $buckets[$month]['production'] = Money::add($buckets[$month]['production'], $value);
            $buckets[$month]['inflow'] = Money::add($buckets[$month]['inflow'], $value);
        }

        $forecast = [];
        $purchaseTotal = '0';
        foreach ($buckets as $month => $values) {
            $forecast[] = [
                'month' => $month,
                'projected_production_cost' => Money::toFloat($values['production']),
                'projected_material_purchase_cost' => Money::toFloat($values['purchase']),
                'projected_inventory_inflow' => Money::toFloat($values['inflow']),
            ];
            $purchaseTotal = Money::add($purchaseTotal, $values['purchase']);
        }

        $currency = TenantAccountingCurrency::of($tenantId);
        $this->dispatcher->publishAndDispatch(new ProductionCashForecastEvent(monthlyForecast: $forecast, currency: $currency, tenantId: $tenantId));
        $this->dispatcher->publishAndDispatch(new MaterialPurchaseForecastEvent(
            purchaseRequirements: array_map(static fn (array $row): array => ['month' => $row['month'], 'estimated_cost' => $row['projected_material_purchase_cost']], $forecast),
            totalEstimatedOutflow: Money::toFloat($purchaseTotal),
            currency: $currency,
            tenantId: $tenantId
        ));
        $this->dispatcher->publishAndDispatch(new InventoryForecastEvent(
            projectedInventoryValuation: array_map(static fn (array $row): array => ['month' => $row['month'], 'estimated_inflow' => $row['projected_inventory_inflow']], $forecast),
            currency: $currency,
            tenantId: $tenantId
        ));

        return $forecast;
    }

    /**
     * Standard unit cost of an item, cached for the run.
     *
     * @param  array<string, string>  $cache
     */
    private function standard(string $tenantId, string $itemId, array &$cache): string
    {
        if (! isset($cache[$itemId])) {
            $item = MfgItem::query()->where('tenant_id', $tenantId)->find($itemId);
            $cache[$itemId] = $item instanceof MfgItem ? $item->standardUnitCost() : '0';
        }

        return $cache[$itemId];
    }
}
