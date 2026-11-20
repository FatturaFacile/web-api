<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forecasting\Application\Commands\GenerateSkuDemandForecastCommand;
use Modules\Forecasting\Domain\Contracts\ForecastingEngineInterface;
use Modules\Forecasting\Domain\Entities\SkuDemandForecast;
use Modules\Forecasting\Domain\Exceptions\ForecastingException;
use Modules\Forecasting\Domain\Services\NormalDistribution;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;
use Modules\Forecasting\Infrastructure\Persistence\ForecastingDataReader;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;

/**
 * Forecasts the demand of every product and warehouse with sales in the trailing months (level, trend and yearly
 * seasonality), measures the supplier lead time from the purchase orders and their goods receipts, and sizes the safety
 * stock and the reorder point with the standard formulas.
 */
final readonly class GenerateSkuDemandForecastHandler
{
    private const int MinimumActiveMonths = 3;

    private const int DefaultLeadTimeDays = 7;

    public function __construct(
        private ForecastingDataReader $reader,
        private ForecastingEngineInterface $engine,
        private ForecastingTenantRunner $runner,
    ) {}

    /**
     * @return list<SkuDemandForecastRecord>
     *
     * @throws ForecastingException
     */
    public function handle(GenerateSkuDemandForecastCommand $command): array
    {
        if ($command->periodsAhead < 1 || $command->periodsAhead > 12 || $command->historyMonths < 6) {
            throw new ForecastingException('Forecast 1 to 12 months ahead from at least 6 months of history.');
        }

        return $this->runner->run($command->tenantId, fn (): array => $this->generate($command));
    }

    /**
     * @return list<SkuDemandForecastRecord>
     */
    private function generate(GenerateSkuDemandForecastCommand $command): array
    {
        $periodStart = CarbonImmutable::today()->startOfMonth();
        $historyStart = $periodStart->subMonths($command->historyMonths);
        $sales = $this->reader->monthlySales($command->tenantId, $historyStart, $periodStart->subDay(), $command->productId, $command->warehouseId);
        $records = [];

        DB::transaction(function () use ($command, $sales, $periodStart, $historyStart, &$records): void {
            foreach ($sales as $productId => $warehouses) {
                foreach ($warehouses as $warehouseId => $months) {
                    if (count(array_filter($months, static fn (float $quantity): bool => $quantity > 0.0)) < self::MinimumActiveMonths) {
                        continue;
                    }

                    $records[] = $this->forecastSku($command, (string) $productId, (string) $warehouseId, $months, $periodStart, $historyStart);
                }
            }
        });

        return $records;
    }

    /**
     * @param  array<string, float>  $months  'Y-m' => quantity sold
     */
    private function forecastSku(GenerateSkuDemandForecastCommand $command, string $productId, string $warehouseId, array $months, CarbonImmutable $periodStart, CarbonImmutable $historyStart): SkuDemandForecastRecord
    {
        $series = [];

        for ($month = $historyStart; $month < $periodStart; $month = $month->addMonth()) {
            $series[] = $months[$month->format('Y-m')] ?? 0.0;
        }

        $result = $this->engine->forecast($series, 12, $command->periodsAhead);
        $predicted = array_sum($result->predictions);
        $margin = NormalDistribution::Z80 * $result->residualStdDev * sqrt($command->periodsAhead);

        $leadTimes = $this->reader->supplierLeadTimes($command->tenantId, $productId, $historyStart);
        [$leadTime, $leadTimeStdDev] = $leadTimes === [] ? [$this->configuredLeadTime($productId, $warehouseId), 0.0] : $this->meanAndStdDev($leadTimes);

        $demandPerDay = $result->predictions[0] / SkuDemandForecast::DaysPerMonth;
        $demandStdDevPerDay = $result->residualStdDev / sqrt(SkuDemandForecast::DaysPerMonth);
        $safetyStock = SkuDemandForecast::safetyStock($command->serviceLevelZScore, $leadTime, $demandStdDevPerDay, $demandPerDay, $leadTimeStdDev);
        $reorderPoint = SkuDemandForecast::reorderPoint($demandPerDay, $leadTime, $safetyStock);

        // whereDate: a date column is stored with a time part on some drivers, so an equality on the bare date would miss it.
        $record = SkuDemandForecastRecord::query()
            ->where('tenant_id', $command->tenantId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereDate('forecast_period_start', $periodStart->toDateString())
            ->first() ?? new SkuDemandForecastRecord;
        $record->fill([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'forecast_period_start' => $periodStart->toDateString(),
            'forecast_period_end' => $periodStart->addMonths($command->periodsAhead - 1)->endOfMonth()->toDateString(),
            'predicted_demand_qty' => round($predicted, 4),
            'confidence_lower_bound' => round(max(0.0, $predicted - $margin), 4),
            'confidence_upper_bound' => round($predicted + $margin, 4),
            'recommended_reorder_point' => round($reorderPoint, 4),
            'recommended_safety_stock' => round($safetyStock, 4),
            'seasonality_index' => round($result->seasonalityIndex, 4),
            'supplier_lead_time_avg_days' => round($leadTime, 2),
            'supplier_lead_time_std_dev_days' => round($leadTimeStdDev, 2),
            'model' => $result->model,
            'applied_to_wms_at' => null,
        ]);
        $record->tenant_id = $command->tenantId;
        $record->save();

        return $record;
    }

    /**
     * @param  list<int>  $values
     * @return array{0: float, 1: float}
     */
    private function meanAndStdDev(array $values): array
    {
        $count = count($values);
        $mean = array_sum($values) / $count;

        if ($count < 2) {
            return [$mean, 0.0];
        }

        $variance = array_sum(array_map(static fn (int $value): float => ($value - $mean) ** 2, $values)) / ($count - 1);

        return [$mean, sqrt($variance)];
    }

    /**
     * The lead time of the reorder rule of the product, when the purchase history has none.
     */
    private function configuredLeadTime(string $productId, string $warehouseId): float
    {
        $item = StockItem::query()->where('product_id', $productId)->first();

        if ($item === null) {
            return (float) self::DefaultLeadTimeDays;
        }

        $rule = StockReorderRule::query()->where('stock_item_id', $item->id)->where('warehouse_id', $warehouseId)->first();

        return (float) ($rule->lead_time_days ?? self::DefaultLeadTimeDays);
    }
}
