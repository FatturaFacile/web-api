<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * What a bin holds and whether more fits. Occupancy is read from the quants: units on hand, kilograms (units times the
 * item's gross weight) and cubic metres (units times the item's volume). A capacity the bin does not declare is not
 * checked; a weight or volume limit is not checked for an item whose weight or volume is unknown, since nothing could
 * be computed honestly.
 */
final class WarehouseCapacityService
{
    /**
     * @param  list<string>  $binIds
     * @return array<string, array{quantity: string, weight_kg: string, volume_m3: string, items: int}>
     */
    public function occupancy(array $binIds): array
    {
        if ($binIds === []) {
            return [];
        }

        $rows = StockQuant::query()
            ->join('stock_items as i', 'i.id', '=', 'stock_quants.stock_item_id')
            ->whereIn('stock_quants.warehouse_bin_id', $binIds)
            ->where('stock_quants.quantity_on_hand', '>', 0)
            ->groupBy('stock_quants.warehouse_bin_id')
            ->selectRaw('stock_quants.warehouse_bin_id, SUM(stock_quants.quantity_on_hand) AS quantity, SUM(stock_quants.quantity_on_hand * COALESCE(i.weight_kg, 0)) AS weight_kg, SUM(stock_quants.quantity_on_hand * COALESCE(i.volume_m3, 0)) AS volume_m3, COUNT(DISTINCT stock_quants.stock_item_id) AS items')
            ->toBase()
            ->get()
            ->keyBy('warehouse_bin_id');

        $occupancy = [];

        foreach ($binIds as $binId) {
            $row = $rows->get($binId);
            $occupancy[$binId] = [
                'quantity' => Quantity::of($row->quantity ?? '0'),
                'weight_kg' => Quantity::of($row->weight_kg ?? '0', 3),
                'volume_m3' => Quantity::of($row->volume_m3 ?? '0', 5),
                'items' => (int) ($row->items ?? 0),
            ];
        }

        return $occupancy;
    }

    /**
     * Whether `quantity` units of the item still fit in the bin, given what it holds.
     *
     * @param  array{quantity: string, weight_kg: string, volume_m3: string, items: int}  $occupancy
     */
    public function fits(WarehouseBin $bin, StockItem $item, string $quantity, array $occupancy): bool
    {
        if ($bin->max_quantity !== null && Quantity::compare(Quantity::add($occupancy['quantity'], $quantity), (string) $bin->max_quantity) > 0) {
            return false;
        }

        if ($bin->max_weight_kg !== null && $item->weight_kg !== null
            && Quantity::compare(Quantity::add($occupancy['weight_kg'], Quantity::mul($quantity, (string) $item->weight_kg, 3), 3), (string) $bin->max_weight_kg) > 0) {
            return false;
        }

        return ! ($bin->max_volume_cbm !== null && $item->volume_m3 !== null
            && Quantity::compare(Quantity::add($occupancy['volume_m3'], Quantity::mul($quantity, (string) $item->volume_m3, 5), 5), (string) $bin->max_volume_cbm) > 0);
    }

    /**
     * Bins with their location path and occupancy, for the bin master.
     *
     * @param  Collection<int, WarehouseBin>  $bins
     * @return list<array<string, mixed>>
     */
    public function describe(Collection $bins): array
    {
        $occupancy = $this->occupancy($bins->map(static fn (WarehouseBin $bin): string => (string) $bin->id)->all());

        return $bins->map(static fn (WarehouseBin $bin): array => [
            'id' => (string) $bin->id,
            'warehouse_id' => $bin->rack?->aisle?->zone?->warehouse_id,
            'zone_id' => $bin->rack?->aisle?->warehouse_zone_id,
            'zone_code' => $bin->rack?->aisle?->zone?->code,
            'zone_type' => $bin->rack?->aisle?->zone?->zone_type,
            'aisle_code' => $bin->rack?->aisle?->code,
            'rack_code' => $bin->rack?->code,
            'code' => $bin->code,
            'barcode' => $bin->barcode,
            'bin_type' => $bin->bin_type ?? 'storage',
            'pick_sequence' => $bin->pick_sequence,
            'is_locked' => (bool) $bin->is_locked,
            'max_quantity' => $bin->max_quantity !== null ? Quantity::of((string) $bin->max_quantity) : null,
            'max_weight_kg' => $bin->max_weight_kg !== null ? (string) $bin->max_weight_kg : null,
            'max_volume_cbm' => $bin->max_volume_cbm !== null ? (string) $bin->max_volume_cbm : null,
            'occupancy' => $occupancy[(string) $bin->id],
        ])->values()->all();
    }
}
