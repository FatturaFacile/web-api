<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;
use Modules\Forecasting\Infrastructure\Persistence\ForecastingDataReader;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;

/**
 * The replenishment grid: the latest forecast of every product and warehouse next to the current stock and thresholds,
 * with the last 12 months of sales for the history chart.
 */
final readonly class ListSkuDemandForecastsHandler
{
    public function __construct(
        private ForecastingDataReader $reader,
        private ForecastingTenantRunner $runner,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(string $tenantId, ?string $warehouseId = null, ?string $search = null): array
    {
        return $this->runner->run($tenantId, function () use ($tenantId, $warehouseId, $search): array {
            $latest = [];

            foreach (SkuDemandForecastRecord::query()->where('tenant_id', $tenantId)->when($warehouseId, fn ($query, string $id) => $query->where('warehouse_id', $id))->orderByDesc('forecast_period_start')->get() as $forecast) {
                $latest[$forecast->product_id.'|'.$forecast->warehouse_id] ??= $forecast;
            }

            $products = DB::table('products')->where('tenant_id', $tenantId)->whereIn('id', array_unique(array_map(fn (SkuDemandForecastRecord $forecast): string => $forecast->product_id, $latest)))->get(['id', 'sku', 'name'])->keyBy('id');
            $warehouses = DB::table('warehouses')->where('tenant_id', $tenantId)->pluck('name', 'id');
            $items = StockItem::query()->whereIn('product_id', $products->keys()->all())->get()->keyBy('product_id');
            $rules = StockReorderRule::query()->whereIn('stock_item_id', $items->pluck('id')->all())->get()->groupBy('stock_item_id');
            $end = CarbonImmutable::today()->startOfMonth()->subDay();
            $start = $end->startOfMonth()->subMonths(11);
            $rows = [];

            foreach ($latest as $forecast) {
                $product = $products->get($forecast->product_id);

                if ($product === null || ($search !== null && $search !== '' && ! str_contains(mb_strtolower($product->sku.' '.$product->name), mb_strtolower($search)))) {
                    continue;
                }

                $item = $items->get($forecast->product_id);
                $rule = $item !== null ? ($rules->get($item->id) ?? collect())->firstWhere('warehouse_id', $forecast->warehouse_id) : null;
                $months = $this->reader->monthlySales($tenantId, $start, $end, $forecast->product_id, $forecast->warehouse_id)[$forecast->product_id][$forecast->warehouse_id] ?? [];
                $history = [];

                for ($month = $start; $month <= $end; $month = $month->addMonth()) {
                    $history[] = ['month' => $month->format('Y-m'), 'quantity' => $months[$month->format('Y-m')] ?? 0.0];
                }

                $rows[] = [
                    'forecast_id' => $forecast->id,
                    'product_id' => $forecast->product_id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'warehouse_id' => $forecast->warehouse_id,
                    'warehouse_name' => $warehouses[$forecast->warehouse_id] ?? null,
                    'period_start' => $forecast->forecast_period_start->toDateString(),
                    'period_end' => $forecast->forecast_period_end->toDateString(),
                    'predicted_demand_qty' => (float) $forecast->predicted_demand_qty,
                    'confidence_lower_bound' => (float) $forecast->confidence_lower_bound,
                    'confidence_upper_bound' => (float) $forecast->confidence_upper_bound,
                    'current_stock' => $this->reader->stockOnHand($tenantId, $forecast->product_id, $forecast->warehouse_id),
                    'current_reorder_point' => $rule !== null ? (float) $rule->reorder_point : null,
                    'current_safety_stock' => $rule !== null ? (float) $rule->min_stock_level : null,
                    'recommended_reorder_point' => (float) $forecast->recommended_reorder_point,
                    'recommended_safety_stock' => (float) $forecast->recommended_safety_stock,
                    'seasonality_index' => (float) $forecast->seasonality_index,
                    'supplier_lead_time_avg_days' => (float) $forecast->supplier_lead_time_avg_days,
                    'supplier_lead_time_std_dev_days' => (float) $forecast->supplier_lead_time_std_dev_days,
                    'model' => $forecast->model,
                    'applied_to_wms_at' => $forecast->applied_to_wms_at?->toIso8601String(),
                    'history' => $history,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => [$a['sku'], $a['warehouse_name']] <=> [$b['sku'], $b['warehouse_name']]);

            return $rows;
        });
    }
}
