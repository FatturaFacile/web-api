<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forecasting\Application\Queries\GetSkuForecastVsActualAccuracyQuery;
use Modules\Forecasting\Domain\ValueObjects\ForecastAccuracyMetrics;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;
use Modules\Forecasting\Infrastructure\Persistence\ForecastingDataReader;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;

/**
 * Compares the forecasts of closed periods with the quantities actually sold and returns MAPE and WAPE overall, per
 * warehouse and per SKU.
 */
final readonly class GetSkuForecastVsActualAccuracyHandler
{
    public function __construct(
        private ForecastingDataReader $reader,
        private ForecastingTenantRunner $runner,
    ) {}

    /**
     * @return array{global: array<string, float|int>, by_warehouse: list<array<string, mixed>>, by_sku: list<array<string, mixed>>, periods: list<array<string, mixed>>}
     */
    public function handle(GetSkuForecastVsActualAccuracyQuery $query): array
    {
        return $this->runner->run($query->tenantId, function () use ($query): array {
            $forecasts = SkuDemandForecastRecord::query()
                ->where('tenant_id', $query->tenantId)
                ->where('forecast_period_end', '<', CarbonImmutable::today()->toDateString())
                ->when($query->productId, fn ($builder, string $id) => $builder->where('product_id', $id))
                ->when($query->warehouseId, fn ($builder, string $id) => $builder->where('warehouse_id', $id))
                ->orderBy('forecast_period_start')
                ->get();

            $periods = [];
            $byWarehouse = [];
            $bySku = [];

            foreach ($forecasts as $forecast) {
                $actual = $this->reader->soldQuantity($query->tenantId, $forecast->product_id, $forecast->warehouse_id, CarbonImmutable::parse($forecast->forecast_period_start), CarbonImmutable::parse($forecast->forecast_period_end));
                $predicted = (float) $forecast->predicted_demand_qty;
                $periods[] = [
                    'product_id' => $forecast->product_id,
                    'warehouse_id' => $forecast->warehouse_id,
                    'period_start' => $forecast->forecast_period_start->toDateString(),
                    'period_end' => $forecast->forecast_period_end->toDateString(),
                    'forecast_qty' => $predicted,
                    'actual_qty' => $actual,
                ];
                $byWarehouse[$forecast->warehouse_id]['actual'][] = $actual;
                $byWarehouse[$forecast->warehouse_id]['forecast'][] = $predicted;
                $bySku[$forecast->product_id]['actual'][] = $actual;
                $bySku[$forecast->product_id]['forecast'][] = $predicted;
            }

            $global = ForecastAccuracyMetrics::fromPairs(array_column($periods, 'actual_qty'), array_column($periods, 'forecast_qty'));
            $warehouseNames = DB::table('warehouses')->where('tenant_id', $query->tenantId)->whereIn('id', array_keys($byWarehouse))->pluck('name', 'id');
            $products = DB::table('products')->where('tenant_id', $query->tenantId)->whereIn('id', array_keys($bySku))->get(['id', 'sku', 'name'])->keyBy('id');

            return [
                'global' => $global->toArray(),
                'by_warehouse' => array_map(
                    fn (string $id, array $pairs): array => ['warehouse_id' => $id, 'warehouse_name' => $warehouseNames[$id] ?? null] + ForecastAccuracyMetrics::fromPairs($pairs['actual'], $pairs['forecast'])->toArray(),
                    array_keys($byWarehouse),
                    $byWarehouse,
                ),
                'by_sku' => array_map(
                    fn (string $id, array $pairs): array => ['product_id' => $id, 'sku' => $products[$id]->sku ?? null, 'name' => $products[$id]->name ?? null] + ForecastAccuracyMetrics::fromPairs($pairs['actual'], $pairs['forecast'])->toArray(),
                    array_keys($bySku),
                    $bySku,
                ),
                'periods' => $periods,
            ];
        });
    }
}
