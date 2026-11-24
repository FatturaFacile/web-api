<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Contracts\InventoryLedgerGatewayInterface;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Exceptions\InventoryValuationConflictException;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\InventoryCostLayerConsumption;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Decimal;

/**
 * Year-end inventory book (libro inventari, art. 2217 c.c. / art. 958 CO): quantities per
 * item / lot / warehouse as of a date, valued per the item's effective method, reconciled
 * against the General Ledger inventory account balance.
 *
 * @phpstan-type BookRow array{sku: string, name: string, uom: string, warehouse_id: string|null, warehouse_code: string|null, lot_number: string|null, quantity: string, unit_cost: string, total_value: string, valuation_method: string}
 * @phpstan-type Book array{meta: array{year: int, as_of: string, company_id: string, warehouse_id: string|null, method_breakdown: array<string, array{rows: int, quantity: string, total_value: string}>, generated_at: string}, rows: list<BookRow>, totals: array{quantity: string, total_value: string}, reconciliation: array{gl_inventory_balance: string, difference: string}}
 */
final class InventoryBookService
{
    public const string CSV_HEADER = 'sku;name;uom;warehouse;lot;quantity;unit_cost;total_value;method';

    public function __construct(
        private readonly InventoryCostingServiceInterface $costing,
        private readonly InventoryLedgerGatewayInterface $ledger
    ) {}

    /**
     * @return Book
     *
     * @throws ValidationException 422 when the fiscal year is unknown or as_of is outside it
     * @throws InventoryValuationConflictException 409 when $requireClosed and as_of is in an open period
     */
    public function build(string $tenantId, string $companyId, int $year, ?string $asOf = null, ?string $warehouseId = null, bool $requireClosed = false): array
    {
        $fiscalYear = $this->ledger->findFiscalYear($tenantId, $companyId, $year);
        if ($fiscalYear === null) {
            throw ValidationException::withMessages(['year' => ["Fiscal year {$year} not found."]]);
        }

        $asOfDate = $asOf !== null ? Carbon::parse($asOf)->toDateString() : $fiscalYear['end_date'];
        if ($asOfDate < $fiscalYear['start_date'] || $asOfDate > $fiscalYear['end_date']) {
            throw ValidationException::withMessages(['as_of' => ["The as_of date must fall within fiscal year {$year} ({$fiscalYear['start_date']} - {$fiscalYear['end_date']})."]]);
        }

        if ($requireClosed && ! $this->ledger->isDateInClosedPeriod($tenantId, $companyId, $asOfDate)) {
            throw new InventoryValuationConflictException("The period containing {$asOfDate} is not closed yet.");
        }

        $asOfEnd = Carbon::parse($asOfDate)->endOfDay();
        $quantities = $this->quantitiesAsOf($asOfEnd);

        $itemIds = array_values(array_unique(array_map(static fn (array $row): string => $row['item_id'], $quantities)));
        /** @var Collection<string, StockItem> $items */
        $items = StockItem::query()->with('category')->whereIn('id', $itemIds)->get()->keyBy('id');
        $lots = StockLot::query()->whereIn('id', array_values(array_filter(array_column($quantities, 'lot_id'))))->pluck('lot_number', 'id');
        $warehouses = Warehouse::query()->whereIn('id', array_values(array_filter(array_column($quantities, 'warehouse_id'))))->pluck('code', 'id');
        $fifoCosts = $this->fifoUnitCostsAsOf($itemIds, $asOfEnd);

        $rows = [];
        $breakdown = [];
        $totalQuantity = '0';
        $totalValue = '0';

        foreach ($quantities as $entry) {
            if ($warehouseId !== null && $entry['warehouse_id'] !== $warehouseId) {
                continue;
            }

            $item = $items->get($entry['item_id']);
            if ($item === null || ! Decimal::isPositive($entry['quantity'])) {
                continue;
            }

            $method = $this->costing->resolveEffectiveMethod($item);
            $unitCost = match ($method) {
                ValuationMethod::Fifo => $fifoCosts[$item->id.'|'.($entry['lot_id'] ?? '')]
                    ?? $fifoCosts[$item->id.'|*']
                    ?? Decimal::unitCost((string) $item->current_moving_average_cost),
                ValuationMethod::WeightedAverage => Decimal::unitCost((string) $item->current_moving_average_cost),
                ValuationMethod::StandardCost => Decimal::unitCost((string) $item->standard_cost),
            };

            $quantity = Decimal::quantity($entry['quantity']);
            $value = Decimal::money(Decimal::mul($quantity, $unitCost));

            $rows[] = [
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'uom' => (string) $item->uom,
                'warehouse_id' => $entry['warehouse_id'],
                'warehouse_code' => $entry['warehouse_id'] !== null ? ($warehouses[$entry['warehouse_id']] ?? null) : null,
                'lot_number' => $entry['lot_id'] !== null ? ($lots[$entry['lot_id']] ?? null) : null,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_value' => $value,
                'valuation_method' => $method->value,
            ];

            $breakdown[$method->value] ??= ['rows' => 0, 'quantity' => '0.0000', 'total_value' => '0.00'];
            $breakdown[$method->value]['rows']++;
            $breakdown[$method->value]['quantity'] = Decimal::quantity(Decimal::add($breakdown[$method->value]['quantity'], $quantity));
            $breakdown[$method->value]['total_value'] = Decimal::money(Decimal::add($breakdown[$method->value]['total_value'], $value));

            $totalQuantity = Decimal::add($totalQuantity, $quantity);
            $totalValue = Decimal::add($totalValue, $value);
        }

        usort($rows, static fn (array $a, array $b): int => [$a['sku'], (string) $a['warehouse_code'], (string) $a['lot_number']] <=> [$b['sku'], (string) $b['warehouse_code'], (string) $b['lot_number']]);
        ksort($breakdown);

        $glBalance = $this->ledger->inventoryAccountBalance($tenantId, $companyId, $asOfDate, $this->inventoryAccountOverrides());

        return [
            'meta' => [
                'year' => $year,
                'as_of' => $asOfDate,
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'method_breakdown' => $breakdown,
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
            'rows' => $rows,
            'totals' => [
                'quantity' => Decimal::quantity($totalQuantity),
                'total_value' => Decimal::money($totalValue),
            ],
            'reconciliation' => [
                'gl_inventory_balance' => Decimal::money($glBalance),
                'difference' => Decimal::money(Decimal::sub($totalValue, $glBalance)),
            ],
        ];
    }

    /**
     * CSV (UTF-8, `;` separator, 4-decimal quantities/costs, 2-decimal values).
     *
     * @param  array{rows: list<array<string, mixed>>}  $book
     */
    public function toCsv(array $book): string
    {
        $lines = [self::CSV_HEADER];
        foreach ($book['rows'] as $row) {
            $lines[] = implode(';', array_map([$this, 'csvField'], [
                (string) $row['sku'],
                (string) $row['name'],
                (string) $row['uom'],
                (string) ($row['warehouse_code'] ?? $row['warehouse_id'] ?? ''),
                (string) ($row['lot_number'] ?? ''),
                (string) $row['quantity'],
                (string) $row['unit_cost'],
                (string) $row['total_value'],
                (string) $row['valuation_method'],
            ]));
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function csvField(string $value): string
    {
        if (preg_match('/[;"\r\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Quantities per item / warehouse / lot as of $asOfEnd: current quants rolled back by the
     * movements executed after that instant.
     *
     * @return list<array{item_id: string, warehouse_id: string|null, lot_id: string|null, quantity: string}>
     */
    private function quantitiesAsOf(Carbon $asOfEnd): array
    {
        $quants = StockQuant::query()->get(['warehouse_bin_id', 'stock_item_id', 'lot_id', 'quantity_on_hand']);
        $laterMovements = StockMovement::query()
            ->whereNotNull('stock_item_id')
            ->where('executed_at', '>', $asOfEnd)
            ->get(['stock_item_id', 'source_location_id', 'destination_location_id', 'lot_id', 'quantity']);

        $binIds = $quants->pluck('warehouse_bin_id')
            ->merge($laterMovements->pluck('source_location_id'))
            ->merge($laterMovements->pluck('destination_location_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $binWarehouse = WarehouseBin::query()
            ->with('rack.aisle.zone')
            ->whereIn('id', $binIds)
            ->get()
            ->mapWithKeys(static fn (WarehouseBin $bin): array => [(string) $bin->id => $bin->rack?->aisle?->zone?->warehouse_id !== null ? (string) $bin->rack->aisle->zone->warehouse_id : null]);

        /** @var array<string, array{item_id: string, warehouse_id: string|null, lot_id: string|null, quantity: string}> $buckets */
        $buckets = [];
        $add = static function (string $itemId, ?string $binId, ?string $lotId, string $quantity) use (&$buckets, $binWarehouse): void {
            $warehouseId = $binId !== null ? ($binWarehouse[$binId] ?? null) : null;
            $key = $itemId.'|'.($warehouseId ?? '').'|'.($lotId ?? '');
            $buckets[$key] ??= ['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'lot_id' => $lotId, 'quantity' => '0'];
            $buckets[$key]['quantity'] = Decimal::add($buckets[$key]['quantity'], $quantity);
        };

        foreach ($quants as $quant) {
            $add((string) $quant->stock_item_id, (string) $quant->warehouse_bin_id, $quant->lot_id !== null ? (string) $quant->lot_id : null, (string) $quant->quantity_on_hand);
        }

        foreach ($laterMovements as $movement) {
            $lotId = $movement->lot_id !== null ? (string) $movement->lot_id : null;
            if ($movement->destination_location_id !== null) {
                $add((string) $movement->stock_item_id, (string) $movement->destination_location_id, $lotId, Decimal::neg((string) $movement->quantity));
            }
            if ($movement->source_location_id !== null) {
                $add((string) $movement->stock_item_id, (string) $movement->source_location_id, $lotId, (string) $movement->quantity);
            }
        }

        return array_values($buckets);
    }

    /**
     * FIFO unit cost per item|lot (and item|* for the whole item) from the layers open at $asOfEnd.
     *
     * @param  list<string>  $itemIds
     * @return array<string, string>
     */
    private function fifoUnitCostsAsOf(array $itemIds, Carbon $asOfEnd): array
    {
        if ($itemIds === []) {
            return [];
        }

        $layers = InventoryCostLayer::query()
            ->whereIn('stock_item_id', $itemIds)
            ->where('received_at', '<=', $asOfEnd)
            ->get();

        $consumedAfter = InventoryCostLayerConsumption::query()
            ->whereIn('layer_id', $layers->pluck('id')->all())
            ->where('consumed_at', '>', $asOfEnd)
            ->get(['layer_id', 'quantity'])
            ->groupBy('layer_id')
            ->map(static fn (Collection $rows): string => $rows->reduce(static fn (string $carry, InventoryCostLayerConsumption $c): string => Decimal::add($carry, (string) $c->quantity), '0'));

        /** @var array<string, array{quantity: string, value: string}> $accumulators */
        $accumulators = [];
        foreach ($layers as $layer) {
            $remaining = Decimal::add((string) $layer->remaining_quantity, $consumedAfter[(string) $layer->id] ?? '0');
            if (! Decimal::isPositive($remaining)) {
                continue;
            }
            $value = Decimal::mul($remaining, $layer->effectiveUnitCost());
            foreach ([$layer->stock_item_id.'|'.($layer->lot_id ?? ''), $layer->stock_item_id.'|*'] as $key) {
                $accumulators[$key] ??= ['quantity' => '0', 'value' => '0'];
                $accumulators[$key]['quantity'] = Decimal::add($accumulators[$key]['quantity'], $remaining);
                $accumulators[$key]['value'] = Decimal::add($accumulators[$key]['value'], $value);
            }
        }

        $costs = [];
        foreach ($accumulators as $key => $acc) {
            $costs[$key] = Decimal::unitCost(Decimal::div($acc['value'], $acc['quantity']));
        }

        return $costs;
    }

    /**
     * @return list<string>
     */
    private function inventoryAccountOverrides(): array
    {
        return StockItemCategory::query()
            ->whereNotNull('inventory_account_id')
            ->pluck('inventory_account_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
