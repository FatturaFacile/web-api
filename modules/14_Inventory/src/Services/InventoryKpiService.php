<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Support\Quantity;

/**
 * Warehouse KPIs over a period, all computed from the ledger:
 *
 * - inventory turnover = cost of the stock consumed in the period / average stock value (mean of the value at the
 *   start and at the end, StockValuationReportService); null when the average value is zero;
 * - coverage days = stock value at the end / average daily consumption value; null without consumption;
 * - dead stock = items with stock on hand and no consumption since `dead_stock_days` days, with their value;
 * - inventory record accuracy = counted lines of the period whose count matched the system quantity / counted lines
 *   (percentage), plus the absolute value of the differences; null when nothing was counted.
 *
 * Consumption follows AbcClassificationService::NON_CONSUMPTION_TYPES (adjustments and scrap are not consumption).
 */
final readonly class InventoryKpiService
{
    public function __construct(private StockValuationReportService $valuation) {}

    /**
     * @return array<string, mixed>
     */
    public function kpis(CarbonImmutable $from, CarbonImmutable $to, ?string $warehouseId, int $deadStockDays): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $startValue = $this->valuation->report($start, $warehouseId)['total_value'];
        $endReport = $this->valuation->report($end, $warehouseId);
        $endValue = $endReport['total_value'];
        $averageValue = (string) Quantity::div(Quantity::add($startValue, $endValue, 2), '2', 2);

        $consumption = $this->consumption($warehouseId)->whereBetween('executed_at', [$start, $end]);
        $consumedValue = Quantity::of((string) (clone $consumption)->sum('total_valuation'), 2);
        $days = max(1, (int) $start->diffInDays($end->addSecond()));
        $dailyConsumption = Quantity::div($consumedValue, (string) $days, 4);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'warehouse_id' => $warehouseId,
            'period_days' => $days,
            'stock_value_start' => $startValue,
            'stock_value_end' => $endValue,
            'average_stock_value' => $averageValue,
            'consumption_value' => $consumedValue,
            'inventory_turnover' => Quantity::div($consumedValue, $averageValue, 4),
            'coverage_days' => $dailyConsumption !== null && Quantity::isPositive($dailyConsumption) ? Quantity::div($endValue, $dailyConsumption, 2) : null,
            'dead_stock' => $this->deadStock($end, $warehouseId, $deadStockDays, $endReport['items']),
            'accuracy' => $this->accuracy($start, $end, $warehouseId),
        ];
    }

    /**
     * @param  list<array{stock_item_id: string, sku: string, name: string, warehouse_id: string, quantity: string, value: string}>  $valuedRows
     * @return array{days: int, items_count: int, value: string, items: list<array{stock_item_id: string, sku: string, name: string, quantity: string, value: string, last_consumed_at: ?string}>}
     */
    private function deadStock(CarbonImmutable $end, ?string $warehouseId, int $days, array $valuedRows): array
    {
        $since = $end->subDays($days);
        $onHand = StockQuant::query()
            ->where('quantity_on_hand', '>', 0)
            ->when($warehouseId !== null, static fn ($query) => $query->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId)))
            ->distinct()
            ->pluck('stock_item_id')
            ->map(static fn ($id): string => (string) $id);

        $active = $this->consumption($warehouseId)->where('executed_at', '>=', $since)->distinct()->pluck('stock_item_id')->map(static fn ($id): string => (string) $id)->flip();
        $lastConsumed = $this->consumption($warehouseId)->whereIn('stock_item_id', $onHand)->groupBy('stock_item_id')->selectRaw('stock_item_id, MAX(executed_at) AS last_at')->toBase()->get()->keyBy('stock_item_id');
        $values = collect($valuedRows)->groupBy('stock_item_id');
        $items = StockItem::query()->whereIn('id', $onHand->reject(static fn (string $id): bool => $active->has($id)))->orderBy('sku')->get();
        $rows = [];
        $total = '0.00';

        foreach ($items as $item) {
            $itemRows = $values->get((string) $item->id, collect());
            $quantity = $itemRows->reduce(static fn (string $sum, array $row): string => Quantity::add($sum, $row['quantity']), '0');
            $value = $itemRows->reduce(static fn (string $sum, array $row): string => Quantity::add($sum, $row['value'], 2), '0.00');
            $total = Quantity::add($total, $value, 2);
            $rows[] = [
                'stock_item_id' => (string) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'quantity' => Quantity::of($quantity),
                'value' => Quantity::of($value, 2),
                'last_consumed_at' => isset($lastConsumed[(string) $item->id]) ? (string) $lastConsumed[(string) $item->id]->last_at : null,
            ];
        }

        return ['days' => $days, 'items_count' => count($rows), 'value' => Quantity::of($total, 2), 'items' => $rows];
    }

    /**
     * @return array{counted_lines: int, exact_lines: int, accuracy_percent: ?string, absolute_difference_value: string}
     */
    private function accuracy(CarbonImmutable $start, CarbonImmutable $end, ?string $warehouseId): array
    {
        $lines = InventoryCountLine::query()
            ->whereNotNull('counted_quantity')
            ->whereBetween('counted_at', [$start, $end])
            ->when($warehouseId !== null, static fn ($query) => $query->whereHas('session', static fn ($session) => $session->where('warehouse_id', $warehouseId)))
            ->get(['discrepancy_quantity', 'discrepancy_valuation']);

        $exact = $lines->filter(static fn (InventoryCountLine $line): bool => Quantity::isZero((string) $line->discrepancy_quantity))->count();
        $difference = $lines->reduce(static fn (string $sum, InventoryCountLine $line): string => Quantity::add($sum, Quantity::abs((string) $line->discrepancy_valuation, 2), 2), '0.00');

        return [
            'counted_lines' => $lines->count(),
            'exact_lines' => $exact,
            'accuracy_percent' => $lines->isEmpty() ? null : Quantity::div(Quantity::mul((string) $exact, '100'), (string) $lines->count(), 2),
            'absolute_difference_value' => Quantity::of($difference, 2),
        ];
    }

    /**
     * @return Builder<StockMovement>
     */
    private function consumption(?string $warehouseId): Builder
    {
        return StockMovement::query()
            ->whereNotNull('stock_item_id')
            ->whereNotNull('source_location_id')
            ->whereNull('destination_location_id')
            ->whereNotIn('movement_type', AbcClassificationService::NON_CONSUMPTION_TYPES)
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId));
    }
}
