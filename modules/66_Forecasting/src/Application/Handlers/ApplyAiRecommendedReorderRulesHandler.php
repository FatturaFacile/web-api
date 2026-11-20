<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Illuminate\Support\Facades\DB;
use Modules\Forecasting\Application\Commands\ApplyAiRecommendedReorderRulesCommand;
use Modules\Forecasting\Domain\Exceptions\ForecastingException;
use Modules\Forecasting\Domain\Exceptions\ForecastingNotFoundException;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Manufacturing\Models\MfgItem;

/**
 * Writes the recommended safety stock and reorder point of forecasts to the replenishment rule of the product in the
 * warehouse (Mod 051) and to the planning item (Mod 062), all or nothing, and records an immutable audit entry for each.
 */
final readonly class ApplyAiRecommendedReorderRulesHandler
{
    public function __construct(
        private ForecastingTenantRunner $runner,
        private RecordAuditAction $audit,
    ) {}

    /**
     * @return list<array{forecast_id: string, product_id: string, warehouse_id: string, reorder_point: float, safety_stock: float, reorder_rule_id: string, planning_items_updated: int}>
     *
     * @throws ForecastingException when nothing can be applied or a product has no stock item
     */
    public function handle(ApplyAiRecommendedReorderRulesCommand $command): array
    {
        return $this->runner->run($command->tenantId, fn (): array => DB::transaction(fn (): array => $this->apply($command)));
    }

    /**
     * @return list<array{forecast_id: string, product_id: string, warehouse_id: string, reorder_point: float, safety_stock: float, reorder_rule_id: string, planning_items_updated: int}>
     */
    private function apply(ApplyAiRecommendedReorderRulesCommand $command): array
    {
        $forecasts = $this->forecasts($command);

        // A forecast of another tenant is invisible through the tenant scope: it is reported as missing.
        if ($command->forecastIds !== [] && count(array_filter($forecasts, static fn (SkuDemandForecastRecord $forecast): bool => in_array($forecast->id, $command->forecastIds, true))) !== count(array_unique($command->forecastIds))) {
            throw new ForecastingNotFoundException('A forecast does not exist.');
        }

        if ($forecasts === []) {
            throw new ForecastingException('There is no forecast to apply.');
        }

        $results = [];

        foreach ($forecasts as $forecast) {
            $item = StockItem::query()->where('product_id', $forecast->product_id)->first();

            if ($item === null) {
                throw new ForecastingException("The product {$forecast->product_id} has no stock item to hold a reorder rule.");
            }

            $rule = StockReorderRule::query()->firstOrNew(['stock_item_id' => $item->id, 'warehouse_id' => $forecast->warehouse_id]);
            $before = $rule->exists ? ['reorder_point' => $rule->reorder_point, 'min_stock_level' => $rule->min_stock_level] : null;
            $reorderPoint = (float) $forecast->recommended_reorder_point;
            $safetyStock = (float) $forecast->recommended_safety_stock;

            $rule->fill([
                'reorder_point' => round($reorderPoint, 4),
                'min_stock_level' => round($safetyStock, 4),
                'max_stock_level' => round(max((float) ($rule->max_stock_level ?? 0), $reorderPoint + (float) $forecast->predicted_demand_qty), 4),
                'economic_order_quantity' => $rule->exists ? $rule->economic_order_quantity : round(max(1.0, ceil((float) $forecast->predicted_demand_qty)), 4),
                'lead_time_days' => (int) round((float) $forecast->supplier_lead_time_avg_days),
            ]);
            $rule->tenant_id = $command->tenantId;
            $rule->save();

            $planning = 0;

            foreach (MfgItem::query()->where(fn ($query) => $query->where('stock_item_id', $item->id)->orWhere('product_id', $forecast->product_id))->get() as $mfgItem) {
                $mfgItem->safety_stock = number_format($safetyStock, 4, '.', '');
                $mfgItem->save();
                $planning++;
            }

            $forecast->applied_to_wms_at = now();
            $forecast->save();

            $this->audit->execute(
                'forecasting.reorder_rules_applied',
                $forecast,
                $before,
                [
                    'warehouse_id' => $forecast->warehouse_id,
                    'reorder_point' => round($reorderPoint, 4),
                    'safety_stock' => round($safetyStock, 4),
                    'reorder_rule_id' => $rule->id,
                    'planning_items_updated' => $planning,
                ],
                'forecasting',
            );

            $results[] = [
                'forecast_id' => $forecast->id,
                'product_id' => $forecast->product_id,
                'warehouse_id' => $forecast->warehouse_id,
                'reorder_point' => round($reorderPoint, 4),
                'safety_stock' => round($safetyStock, 4),
                'reorder_rule_id' => $rule->id,
                'planning_items_updated' => $planning,
            ];
        }

        return $results;
    }

    /**
     * @return list<SkuDemandForecastRecord>
     */
    private function forecasts(ApplyAiRecommendedReorderRulesCommand $command): array
    {
        $forecasts = [];

        if ($command->forecastIds !== []) {
            foreach (SkuDemandForecastRecord::query()->whereIn('id', $command->forecastIds)->get() as $forecast) {
                $forecasts[$forecast->id] = $forecast;
            }
        }

        foreach ($command->productIds as $productId) {
            $latest = [];

            foreach (SkuDemandForecastRecord::query()->where('product_id', $productId)->orderByDesc('forecast_period_start')->get() as $forecast) {
                $latest[$forecast->warehouse_id] ??= $forecast;
            }

            foreach ($latest as $forecast) {
                $forecasts[$forecast->id] = $forecast;
            }
        }

        return array_values($forecasts);
    }
}
