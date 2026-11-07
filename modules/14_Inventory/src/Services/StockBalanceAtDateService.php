<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;

/**
 * The stock as it stood at a past moment: today's quants with every later movement undone. Each movement moves a
 * quantity out of its source bin and into its destination bin, so undoing it is the opposite on both sides.
 */
final class StockBalanceAtDateService
{
    /**
     * @return Collection<string, array{warehouse_bin_id: string, stock_item_id: string, lot_id: ?string, quantity: string}>
     */
    public function balances(string $warehouseId, CarbonInterface $moment, ?string $stockItemId = null): Collection
    {
        $balances = [];
        $add = static function (string $binId, string $itemId, ?string $lotId, string $quantity) use (&$balances): void {
            $key = self::key($binId, $itemId, $lotId);
            $balances[$key] ??= ['warehouse_bin_id' => $binId, 'stock_item_id' => $itemId, 'lot_id' => $lotId, 'quantity' => '0.0000'];
            $balances[$key]['quantity'] = bcadd($balances[$key]['quantity'], $quantity, 4);
        };

        $quants = StockQuant::query()
            ->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId))
            ->when($stockItemId !== null, static fn ($query) => $query->where('stock_item_id', $stockItemId))
            ->get();

        foreach ($quants as $quant) {
            $add((string) $quant->warehouse_bin_id, (string) $quant->stock_item_id, $quant->lot_id, (string) $quant->quantity_on_hand);
        }

        $binIds = $this->binsOfWarehouse($warehouseId);

        $later = StockMovement::query()
            ->where('executed_at', '>', $moment)
            ->whereNotNull('stock_item_id')
            ->where(static fn ($query) => $query->whereIn('source_location_id', $binIds)->orWhereIn('destination_location_id', $binIds))
            ->when($stockItemId !== null, static fn ($query) => $query->where('stock_item_id', $stockItemId))
            ->get();

        foreach ($later as $movement) {
            $quantity = (string) $movement->quantity;

            if ($movement->source_location_id !== null && in_array($movement->source_location_id, $binIds, true)) {
                $add((string) $movement->source_location_id, (string) $movement->stock_item_id, $movement->lot_id, $quantity);
            }

            if ($movement->destination_location_id !== null && in_array($movement->destination_location_id, $binIds, true)) {
                $add((string) $movement->destination_location_id, (string) $movement->stock_item_id, $movement->lot_id, bcmul($quantity, '-1', 4));
            }
        }

        return collect($balances);
    }

    public static function key(string $binId, string $itemId, ?string $lotId): string
    {
        return $binId.'|'.$itemId.'|'.$lotId;
    }

    /**
     * @return list<string>
     */
    private function binsOfWarehouse(string $warehouseId): array
    {
        return WarehouseBin::query()
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId))
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
