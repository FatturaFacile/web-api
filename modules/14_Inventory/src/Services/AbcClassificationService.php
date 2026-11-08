<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Support\Quantity;

/**
 * ABC classification of the stock items by consumption value (Pareto analysis): items are ranked by the value of the
 * stock that left the warehouses in the period, and the items making up the first `a_share` percent of the cumulative
 * value are class A, those up to `b_share` percent class B, the rest (and the active items without consumption) class C.
 * The shares are parameters of each run; the defaults are the conventional 80/95 split.
 *
 * Consumption is every movement out of stock (source bin, no destination) except corrections and technical movements:
 * adjustments, scrap and the settlement of stock sold beyond availability (the sale itself was already counted).
 * The class is stored on the item and drives the cycle count plans.
 */
final readonly class AbcClassificationService
{
    /** Movement types that take stock out without being consumption. */
    public const array NON_CONSUMPTION_TYPES = [
        'adjustment', 'adjustment_out', 'ADJUSTMENT_OUT', 'scrap_loss', 'SCRAP', 'scrap', ProductStockLedger::SETTLEMENT_MOVEMENT_TYPE,
    ];

    /**
     * @return list<array{stock_item_id: string, sku: string, name: string, consumption_value: string, cumulative_share: string, abc_class: string}>
     */
    public function classify(int $periodDays, string $aShare = '80', string $bShare = '95', bool $persist = true): array
    {
        $since = Carbon::now()->subDays($periodDays);

        $consumption = StockMovement::query()
            ->whereNotNull('stock_item_id')
            ->whereNotNull('source_location_id')
            ->whereNull('destination_location_id')
            ->whereNotIn('movement_type', self::NON_CONSUMPTION_TYPES)
            ->where('executed_at', '>=', $since)
            ->groupBy('stock_item_id')
            ->selectRaw('stock_item_id, SUM(total_valuation) AS consumption_value')
            ->toBase()
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->stock_item_id => Quantity::of((string) $row->consumption_value, 2)]);

        $items = StockItem::query()->where('is_active', true)->get(['id', 'sku', 'name'])->keyBy('id');
        $total = $consumption->reduce(static fn (string $sum, string $value): string => Quantity::add($sum, $value, 2), '0.00');

        $ranked = $items->keys()
            ->map(static fn ($id): array => ['id' => (string) $id, 'value' => $consumption->get((string) $id, '0.00')])
            ->sort(static fn (array $left, array $right): int => Quantity::compare($right['value'], $left['value']) ?: strcmp($left['id'], $right['id']))
            ->values();

        $running = '0.00';
        $rows = [];

        foreach ($ranked as $entry) {
            $item = $items->get($entry['id']);
            $running = Quantity::add($running, $entry['value'], 2);
            $share = Quantity::isPositive($total) ? (string) Quantity::div(Quantity::mul($running, '100', 10), $total, 2) : '100.00';
            $class = match (true) {
                ! Quantity::isPositive($entry['value']) => 'C',
                Quantity::compare($share, $aShare) <= 0 => 'A',
                Quantity::compare($share, $bShare) <= 0 => 'B',
                default => 'C',
            };

            // The item that crosses the A threshold is still A when nothing was A before it (a single dominant item).
            if ($class !== 'A' && Quantity::isPositive($entry['value']) && ! collect($rows)->contains('abc_class', 'A')) {
                $class = 'A';
            }

            $rows[] = [
                'stock_item_id' => $entry['id'],
                'sku' => (string) $item?->sku,
                'name' => (string) $item?->name,
                'consumption_value' => $entry['value'],
                'cumulative_share' => $share,
                'abc_class' => $class,
            ];
        }

        if ($persist) {
            DB::transaction(static function () use ($rows): void {
                foreach (collect($rows)->groupBy('abc_class') as $class => $group) {
                    StockItem::query()->whereIn('id', $group->pluck('stock_item_id'))->update(['abc_class' => $class, 'abc_classified_at' => Carbon::now()]);
                }
            });
        }

        return $rows;
    }
}
