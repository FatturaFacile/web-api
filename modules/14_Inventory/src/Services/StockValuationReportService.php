<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;

/**
 * Stock valuation at a moment, from the single ledger.
 *
 * Quantities are the stock as it stood at that moment (StockBalanceAtDateService: today's quants with every later
 * movement undone). The unit cost follows the valuation method of the item's category, the methods the module models:
 *
 * - `moving_average`: the perpetual average of the ledger up to the moment, i.e. the value of every movement into
 *   stock minus the value of every movement out of stock, divided by the net quantity (relocations between bins are
 *   neither). When the ledger holds no net quantity, the item's current moving average cost is used and the row says
 *   so (`cost_source: current_moving_average`).
 * - `fifo`: the remaining stock is the most recent receipts, so it is valued at the latest receipts up to the moment
 *   (newest first) until the quantity is covered; a part not covered by receipts is valued at the moving average.
 * - `standard_cost`: the item's standard cost. The module keeps no history of standard costs, so the current one is
 *   used for any date (`cost_source: standard_cost`).
 */
final readonly class StockValuationReportService
{
    /** Movements that only exist to keep the ledger balanced when a sale went beyond the stock. */
    private const array TECHNICAL_INBOUND_TYPES = [ProductStockLedger::COVER_MOVEMENT_TYPE];

    public function __construct(private StockBalanceAtDateService $balances) {}

    /**
     * @param  'fifo'|'moving_average'|'standard_cost'|null  $forcedMethod  values every item with this method instead of its category's
     * @return array{as_of: string, warehouse_id: ?string, total_value: string, warehouses: list<array{warehouse_id: string, warehouse_code: string, total_value: string}>, items: list<array{stock_item_id: string, sku: string, name: string, warehouse_id: string, quantity: string, unit_cost: string, value: string, valuation_method: string, cost_source: string}>}
     */
    public function report(CarbonInterface $moment, ?string $warehouseId = null, ?string $forcedMethod = null): array
    {
        $warehouses = Warehouse::query()
            ->when($warehouseId !== null, static fn ($query) => $query->whereKey($warehouseId))
            ->orderBy('code')
            ->get();

        /** @var array<string, array<string, string>> $quantities item id => warehouse id => quantity */
        $quantities = [];

        foreach ($warehouses as $warehouse) {
            foreach ($this->balances->balances((string) $warehouse->id, $moment) as $balance) {
                if (Quantity::isZero($balance['quantity'])) {
                    continue;
                }

                $quantities[$balance['stock_item_id']][(string) $warehouse->id] = Quantity::add($quantities[$balance['stock_item_id']][(string) $warehouse->id] ?? '0', $balance['quantity']);
            }
        }

        $items = StockItem::query()->with('category')->whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $rows = [];
        $byWarehouse = [];
        $total = '0';

        foreach ($quantities as $itemId => $perWarehouse) {
            $item = $items->get($itemId);

            if ($item === null) {
                continue;
            }

            $method = $forcedMethod ?? (string) ($item->category->valuation_method ?? 'moving_average');
            $itemQuantity = array_reduce($perWarehouse, static fn (string $sum, string $quantity): string => Quantity::add($sum, $quantity), '0');
            ['unit_cost' => $unitCost, 'cost_source' => $source] = $this->unitCostAt($item, $method, $moment, $itemQuantity);

            foreach ($perWarehouse as $whId => $quantity) {
                $value = Quantity::value($quantity, $unitCost);
                $rows[] = [
                    'stock_item_id' => (string) $item->id,
                    'sku' => (string) $item->sku,
                    'name' => (string) $item->name,
                    'warehouse_id' => (string) $whId,
                    'quantity' => Quantity::of($quantity),
                    'unit_cost' => $unitCost,
                    'value' => $value,
                    'valuation_method' => $method,
                    'cost_source' => $source,
                ];
                $byWarehouse[$whId] = Quantity::add($byWarehouse[$whId] ?? '0', $value, 2);
                $total = Quantity::add($total, $value, 2);
            }
        }

        usort($rows, static fn (array $left, array $right): int => [$left['sku'], $left['warehouse_id']] <=> [$right['sku'], $right['warehouse_id']]);

        return [
            'as_of' => $moment->toIso8601String(),
            'warehouse_id' => $warehouseId,
            'total_value' => Quantity::of($total, 2),
            'warehouses' => $warehouses->map(static fn (Warehouse $warehouse): array => [
                'warehouse_id' => (string) $warehouse->id,
                'warehouse_code' => (string) $warehouse->code,
                'total_value' => Quantity::of($byWarehouse[(string) $warehouse->id] ?? '0', 2),
            ])->values()->all(),
            'items' => $rows,
        ];
    }

    /**
     * The unit cost of the item at the moment for the given quantity, and where it comes from.
     *
     * @return array{unit_cost: string, cost_source: string}
     */
    public function unitCostAt(StockItem $item, string $method, CarbonInterface $moment, string $quantity): array
    {
        if ($method === 'standard_cost') {
            return ['unit_cost' => Quantity::of((string) $item->standard_cost), 'cost_source' => 'standard_cost'];
        }

        if ($method === 'fifo') {
            return $this->fifoUnitCost($item, $moment, Quantity::of($quantity));
        }

        return $this->movingAverageAt($item, $moment);
    }

    /**
     * @return array{unit_cost: string, cost_source: string}
     */
    private function movingAverageAt(StockItem $item, CarbonInterface $moment): array
    {
        $movements = $this->oneSidedMovements($item, $moment)->get(['source_location_id', 'destination_location_id', 'quantity', 'total_valuation']);
        $quantity = '0';
        $value = '0';

        foreach ($movements as $movement) {
            $sign = $movement->destination_location_id !== null ? '1' : '-1';
            $quantity = Quantity::add($quantity, Quantity::mul((string) $movement->quantity, $sign));
            $value = Quantity::add($value, Quantity::mul((string) $movement->total_valuation, $sign, 2), 2);
        }

        if (Quantity::isPositive($quantity) && ! Quantity::isNegative($value)) {
            return ['unit_cost' => (string) Quantity::div($value, $quantity), 'cost_source' => 'movements'];
        }

        return ['unit_cost' => Quantity::of((string) $item->current_moving_average_cost), 'cost_source' => 'current_moving_average'];
    }

    /**
     * @return array{unit_cost: string, cost_source: string}
     */
    private function fifoUnitCost(StockItem $item, CarbonInterface $moment, string $quantity): array
    {
        if (! Quantity::isPositive($quantity)) {
            return $this->movingAverageAt($item, $moment);
        }

        $receipts = StockMovement::query()
            ->where('stock_item_id', $item->id)
            ->whereNull('source_location_id')
            ->whereNotNull('destination_location_id')
            ->whereNotIn('movement_type', self::TECHNICAL_INBOUND_TYPES)
            ->where('executed_at', '<=', $moment)
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->cursor();

        $remaining = $quantity;
        $value = '0';

        foreach ($receipts as $receipt) {
            if (! Quantity::isPositive($remaining)) {
                break;
            }

            $take = Quantity::min((string) $receipt->quantity, $remaining);
            $value = Quantity::add($value, Quantity::mul($take, (string) $receipt->unit_cost, 10), 10);
            $remaining = Quantity::sub($remaining, $take);
        }

        if (Quantity::isPositive($remaining)) {
            $average = $this->movingAverageAt($item, $moment)['unit_cost'];
            $value = Quantity::add($value, Quantity::mul($remaining, $average, 10), 10);

            return ['unit_cost' => (string) Quantity::div($value, $quantity), 'cost_source' => 'fifo_layers_and_moving_average'];
        }

        return ['unit_cost' => (string) Quantity::div($value, $quantity), 'cost_source' => 'fifo_layers'];
    }

    /**
     * Movements into or out of stock (one side virtual) of the item up to the moment.
     *
     * @return Builder<StockMovement>
     */
    private function oneSidedMovements(StockItem $item, CarbonInterface $moment): Builder
    {
        return StockMovement::query()
            ->where('stock_item_id', $item->id)
            ->where('executed_at', '<=', $moment)
            ->where(static fn ($side) => $side
                ->where(static fn ($in) => $in->whereNull('source_location_id')->whereNotNull('destination_location_id'))
                ->orWhere(static fn ($out) => $out->whereNotNull('source_location_id')->whereNull('destination_location_id')));
    }

    /**
     * The value of the stock of the given items at the moment (all warehouses or one), for the KPIs.
     *
     * @return Collection<string, string> item id => value
     */
    public function valuesByItem(CarbonInterface $moment, ?string $warehouseId = null): Collection
    {
        return collect($this->report($moment, $warehouseId)['items'])
            ->groupBy('stock_item_id')
            ->map(static fn (Collection $rows): string => $rows->reduce(static fn (string $sum, array $row): string => Quantity::add($sum, $row['value'], 2), '0.00'));
    }
}
