<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\PutawayRule;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * Directed putaway: where received stock should be stored, and the move that stores it.
 *
 * Rules of the warehouse are tried from the most specific (the item, then its category, then rules for every item),
 * then by priority. Each rule yields candidate bins (its fixed bin, the bins of its zone or of its bin type), ordered
 * by its strategy, and only bins that are not locked, are of a storable type (storage, picking, bulk), lie outside
 * quarantine and transit zones and have the capacity for the quantity are kept. When no rule yields a bin, the
 * suggestion falls back to bins that already hold the item, then empty bins (flagged `source: fallback`).
 *
 * Stock waiting in quarantine is not put away from here: it is released by the quality decision of the receipt.
 */
final readonly class PutawayService
{
    public function __construct(
        private WarehouseCapacityService $capacity,
        private DoubleEntryMovementServiceInterface $movements
    ) {}

    /**
     * Candidate bins for `quantity` units of the item, best first.
     *
     * @return list<array{bin_id: string, barcode: string, code: string, zone_code: ?string, bin_type: string, rule_id: ?string, strategy: string, source: string, occupancy: array{quantity: string, weight_kg: string, volume_m3: string, items: int}}>
     */
    public function suggest(Warehouse $warehouse, StockItem $item, string $quantity, int $limit = 5): array
    {
        $quantity = Quantity::of($quantity);
        $suggestions = [];
        $seen = [];

        foreach ($this->rulesFor($warehouse, $item) as $rule) {
            foreach ($this->rankedBins($this->candidates($warehouse, $rule), $item, (string) $rule->strategy) as $bin) {
                $this->offer($suggestions, $seen, $bin, $item, $quantity, (string) $rule->id, (string) $rule->strategy, 'rule');
            }
        }

        if ($suggestions === []) {
            foreach ($this->rankedBins($this->storableBins($warehouse)->get(), $item, 'consolidate') as $bin) {
                $this->offer($suggestions, $seen, $bin, $item, $quantity, null, 'consolidate', 'fallback');
            }
        }

        return array_slice($suggestions, 0, max(1, $limit));
    }

    /**
     * Moves stock from a receiving or staging bin to its storage bin: the given one, or the best suggestion.
     *
     * @throws StockRuleViolation when the source is in quarantine, the destination is not a storable bin of the same
     *                            warehouse or lacks capacity, or no bin can take the stock
     */
    public function putAway(WarehouseBin $source, StockItem $item, string $quantity, ?string $lotId, ?WarehouseBin $destination, string $userId): StockMovement
    {
        $quantity = Quantity::of($quantity);
        $sourceZone = $source->rack?->aisle?->zone;

        if ($sourceZone === null) {
            throw new StockRuleViolation('The source bin has no location in a warehouse.');
        }

        if ($sourceZone->zone_type === 'quarantine') {
            throw new StockRuleViolation('Stock in quarantine is released by the quality decision of its receipt, not by putaway.');
        }

        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->findOrFail($sourceZone->warehouse_id);

        if ($destination === null) {
            $best = $this->suggest($warehouse, $item, $quantity, 1)[0] ?? null;

            if ($best === null) {
                throw new StockRuleViolation("No bin of warehouse [{$warehouse->name}] can take {$quantity} units of [{$item->sku}].");
            }

            $destination = WarehouseBin::query()->with('rack.aisle.zone')->findOrFail($best['bin_id']);
        }

        $this->assertStorable($warehouse, $destination, $item, $quantity);

        return $this->movements->executeTransfer(new StockTransferDTO(
            stockItemId: (string) $item->id,
            sourceBinId: (string) $source->id,
            destinationBinId: (string) $destination->id,
            quantity: (float) $quantity,
            movementType: 'putaway',
            lotId: $lotId,
            userId: $userId,
            notes: "Putaway {$source->barcode} -> {$destination->barcode}"
        ));
    }

    /**
     * @return Collection<int, PutawayRule>
     */
    private function rulesFor(Warehouse $warehouse, StockItem $item): Collection
    {
        return PutawayRule::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->where(static function (Builder $match) use ($item): void {
                $match->where('stock_item_id', $item->id)
                    ->orWhere(static fn (Builder $category) => $category->whereNull('stock_item_id')->where('category_id', $item->category_id))
                    ->orWhere(static fn (Builder $generic) => $generic->whereNull('stock_item_id')->whereNull('category_id'));
            })
            ->get()
            ->sortBy(static fn (PutawayRule $rule): array => [
                $rule->stock_item_id !== null ? 0 : ($rule->category_id !== null ? 1 : 2),
                $rule->priority,
                (string) $rule->id,
            ])
            ->values();
    }

    /**
     * @return Collection<int, WarehouseBin>
     */
    private function candidates(Warehouse $warehouse, PutawayRule $rule): Collection
    {
        $query = $this->storableBins($warehouse);

        if ($rule->target_bin_id !== null) {
            $query->whereKey($rule->target_bin_id);
        } elseif ($rule->target_zone_id !== null) {
            $query->whereHas('rack.aisle', static fn ($aisle) => $aisle->where('warehouse_zone_id', $rule->target_zone_id));
        }

        if ($rule->bin_type !== null) {
            $query->where('bin_type', $rule->bin_type);
        }

        return $query->get();
    }

    /**
     * @return Builder<WarehouseBin>
     */
    private function storableBins(Warehouse $warehouse): Builder
    {
        return WarehouseBin::query()
            ->with('rack.aisle.zone')
            ->where('is_locked', false)
            ->whereIn('bin_type', WarehouseBin::PUTAWAY_BIN_TYPES)
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouse->id)->whereNotIn('zone_type', StockAllocationPlanner::BLOCKED_ZONE_TYPES));
    }

    /**
     * Orders the candidates by the strategy: bins holding the item first (consolidate), empty bins first (empty_bin),
     * or the walking order (fixed_location); pick sequence and code break ties.
     *
     * @param  Collection<int, WarehouseBin>  $bins
     * @return Collection<int, WarehouseBin>
     */
    private function rankedBins(Collection $bins, StockItem $item, string $strategy): Collection
    {
        if ($bins->isEmpty()) {
            return $bins;
        }

        $binIds = $bins->map(static fn (WarehouseBin $bin): string => (string) $bin->id)->all();
        $holding = StockQuant::query()->whereIn('warehouse_bin_id', $binIds)->where('stock_item_id', $item->id)->where('quantity_on_hand', '>', 0)->pluck('warehouse_bin_id')->map(static fn ($id): string => (string) $id)->flip();
        $occupied = StockQuant::query()->whereIn('warehouse_bin_id', $binIds)->where('quantity_on_hand', '>', 0)->pluck('warehouse_bin_id')->map(static fn ($id): string => (string) $id)->flip();

        return $bins->sortBy(static fn (WarehouseBin $bin): array => [
            match ($strategy) {
                'consolidate' => $holding->has((string) $bin->id) ? 0 : ($occupied->has((string) $bin->id) ? 2 : 1),
                'empty_bin' => $occupied->has((string) $bin->id) ? 1 : 0,
                default => 0,
            },
            $bin->pick_sequence ?? PHP_INT_MAX,
            (string) $bin->code,
        ])->values();
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, bool>  $seen
     */
    private function offer(array &$suggestions, array &$seen, WarehouseBin $bin, StockItem $item, string $quantity, ?string $ruleId, string $strategy, string $source): void
    {
        if (isset($seen[(string) $bin->id])) {
            return;
        }

        $occupancy = $this->capacity->occupancy([(string) $bin->id])[(string) $bin->id];

        if (! $this->capacity->fits($bin, $item, $quantity, $occupancy)) {
            return;
        }

        $seen[(string) $bin->id] = true;
        $suggestions[] = [
            'bin_id' => (string) $bin->id,
            'barcode' => (string) $bin->barcode,
            'code' => (string) $bin->code,
            'zone_code' => $bin->rack?->aisle?->zone?->code,
            'bin_type' => (string) ($bin->bin_type ?? 'storage'),
            'rule_id' => $ruleId,
            'strategy' => $strategy,
            'source' => $source,
            'occupancy' => $occupancy,
        ];
    }

    private function assertStorable(Warehouse $warehouse, WarehouseBin $destination, StockItem $item, string $quantity): void
    {
        $zone = $destination->rack?->aisle?->zone;

        if ($zone === null || (string) $zone->warehouse_id !== (string) $warehouse->id) {
            throw new StockRuleViolation('The destination bin is not in the warehouse of the source bin.');
        }

        if ($destination->is_locked || ! in_array($destination->bin_type ?? 'storage', WarehouseBin::PUTAWAY_BIN_TYPES, true) || in_array($zone->zone_type, StockAllocationPlanner::BLOCKED_ZONE_TYPES, true)) {
            throw new StockRuleViolation("Bin [{$destination->barcode}] does not take putaway: it is locked, not a storage location or in a quarantine or transit zone.");
        }

        $occupancy = $this->capacity->occupancy([(string) $destination->id])[(string) $destination->id];

        if (! $this->capacity->fits($destination, $item, $quantity, $occupancy)) {
            throw new StockRuleViolation("Bin [{$destination->barcode}] lacks the capacity for {$quantity} more units of [{$item->sku}].");
        }
    }
}
