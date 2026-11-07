<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Enums\PickingStrategy;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;

/**
 * Decides which quants may serve a request and in which order. A quant is out of reach when its bin is locked, it
 * sits in a quarantine or transit area or in a warehouse that does not ship, its lot is quarantined, expired or
 * shorter than the item's minimum remaining shelf life. The rest follows the strategy of the item, then the
 * warehouse, then FIFO.
 *
 * Quants are always locked by id, whatever the strategy: two allocations on the same stock queue on the same rows in
 * the same order and cannot deadlock. The order is then applied to the values read under the lock.
 */
final class StockAllocationPlanner
{
    /** Areas that hold stock which is not available to orders. */
    public const array BLOCKED_ZONE_TYPES = ['quarantine', 'transit'];

    /** Warehouses that hold stock which is not available to orders. */
    public const array NON_SHIPPING_WAREHOUSE_TYPES = ['quarantine', 'transit'];

    public function strategyFor(StockItem $item, Warehouse $warehouse): PickingStrategy
    {
        return PickingStrategy::tryFrom((string) $item->picking_strategy)
            ?? PickingStrategy::tryFrom((string) $warehouse->picking_strategy)
            ?? PickingStrategy::Fifo;
    }

    /**
     * The first day a lot must still be good on to be handed out today.
     */
    public function earliestAcceptableExpiry(StockItem $item, ?CarbonImmutable $onDate = null): string
    {
        return ($onDate ?? CarbonImmutable::today())->addDays((int) $item->min_remaining_shelf_life_days)->toDateString();
    }

    /**
     * Locks the quants that may serve the item and returns them in picking order.
     *
     * @return Collection<int, StockQuant>
     */
    public function lockEligibleQuants(StockItem $item, Warehouse $warehouse, ?string $lotId = null, ?string $binId = null): Collection
    {
        $orderedIds = $this->eligibleQuery($item, $warehouse, $lotId, $binId)->pluck('stock_quants.id')->map(static fn ($id): string => (string) $id)->all();

        if ($orderedIds === []) {
            return collect();
        }

        $position = array_flip($orderedIds);

        return StockQuant::query()
            ->whereIn('id', $orderedIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(static fn (StockQuant $quant): bool => Decimal::isPositive(self::available($quant)))
            ->sortBy(static fn (StockQuant $quant): int => $position[(string) $quant->id])
            ->values();
    }

    /**
     * Splits a quantity across quants already in picking order.
     *
     * @param  Collection<int, StockQuant>  $quants
     * @return list<array{quant: StockQuant, quantity: string}>
     *
     * @throws StockRuleViolation when the quants do not cover the quantity
     */
    public function split(Collection $quants, string $quantity, string $warehouseName): array
    {
        $remaining = Decimal::normalize($quantity);
        $allocations = [];

        foreach ($quants as $quant) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }

            $available = self::available($quant);
            $share = Decimal::compare($available, $remaining) < 0 ? $available : $remaining;

            if (Decimal::isPositive($share)) {
                $allocations[] = ['quant' => $quant, 'quantity' => $share];
                $remaining = Decimal::sub($remaining, $share);
            }
        }

        if (Decimal::isPositive($remaining)) {
            $requested = bcadd($quantity, '0', 4);
            $short = bcadd($remaining, '0', 4);

            throw new StockRuleViolation("Insufficient available stock in warehouse [{$warehouseName}]. Requested: {$requested}, short by: {$short}.");
        }

        return $allocations;
    }

    /**
     * The quants that can serve the item, in picking order, without locking them.
     *
     * @return list<array{quant_id: string, warehouse_bin_id: string, lot_id: ?string, lot_number: ?string, expiration_date: ?string, quantity_available: string}>
     */
    public function availability(StockItem $item, Warehouse $warehouse): array
    {
        return $this->eligibleQuery($item, $warehouse)
            ->addSelect(['l.lot_number as eligible_lot_number', 'l.expiration_date as eligible_expiration_date'])
            ->get()
            ->map(static fn (StockQuant $quant): array => [
                'quant_id' => (string) $quant->id,
                'warehouse_bin_id' => (string) $quant->warehouse_bin_id,
                'lot_id' => $quant->lot_id,
                'lot_number' => $quant->getAttribute('eligible_lot_number'),
                'expiration_date' => $quant->getAttribute('eligible_expiration_date'),
                'quantity_available' => bcadd(self::available($quant), '0', 4),
            ])
            ->all();
    }

    /**
     * What a quant can still give: on hand less reserved, as a decimal string.
     */
    public static function available(StockQuant $quant): string
    {
        return Decimal::sub((string) $quant->quantity_on_hand, (string) $quant->quantity_reserved);
    }

    /**
     * @return Builder<StockQuant>
     */
    private function eligibleQuery(StockItem $item, Warehouse $warehouse, ?string $lotId = null, ?string $binId = null): Builder
    {
        $query = StockQuant::query()
            ->select('stock_quants.*')
            ->join('warehouse_bins as b', 'b.id', '=', 'stock_quants.warehouse_bin_id')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->leftJoin('stock_lots as l', 'l.id', '=', 'stock_quants.lot_id')
            ->where('z.warehouse_id', $warehouse->id)
            ->where('stock_quants.stock_item_id', $item->id)
            ->where('b.is_locked', false)
            ->whereNotIn('z.zone_type', self::BLOCKED_ZONE_TYPES)
            ->whereColumn('stock_quants.quantity_on_hand', '>', 'stock_quants.quantity_reserved')
            ->where(function (Builder|\Illuminate\Database\Query\Builder $lot) use ($item): void {
                $lot->whereNull('stock_quants.lot_id')->orWhere(function ($usable) use ($item): void {
                    $usable->where('l.is_quarantined', false)->where(function ($expiry) use ($item): void {
                        $expiry->whereNull('l.expiration_date')->orWhere('l.expiration_date', '>=', $this->earliestAcceptableExpiry($item));
                    });
                });
            });

        if (in_array($warehouse->warehouse_type, self::NON_SHIPPING_WAREHOUSE_TYPES, true)) {
            $query->whereRaw('1 = 0');
        }

        if ($lotId !== null) {
            $query->where('stock_quants.lot_id', $lotId);
        }

        if ($binId !== null) {
            $query->where('stock_quants.warehouse_bin_id', $binId);
        }

        if ($this->strategyFor($item, $warehouse) === PickingStrategy::Fefo) {
            $query->orderByRaw('l.expiration_date ASC NULLS LAST');
        }

        return $query
            ->orderByRaw('COALESCE(l.received_at, CAST(l.created_at AS date), CAST(stock_quants.created_at AS date)) ASC')
            ->orderBy('stock_quants.id');
    }
}
