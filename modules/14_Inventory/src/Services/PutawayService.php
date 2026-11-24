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
     * Context (all optional): lot_id (lot consolidation and the mixed-lot guard), temperature_control, packaging_unit
     * and abc_class (defaults to the item's own class). Each suggestion carries its utilisation after the putaway, the
     * distance to the relevant dock (class A: shipping, class C: receiving) and the reasons it was ranked there.
     *
     * @param  array{lot_id?: ?string, temperature_control?: ?string, packaging_unit?: ?string, abc_class?: ?string}  $context
     * @return list<array{bin_id: string, barcode: string, code: string, full_path_code: ?string, zone_code: ?string, bin_type: string, rule_id: ?string, strategy: string, source: string, occupancy: array{quantity: string, weight_kg: string, volume_m3: string, items: int}, weight_utilization_pct: ?float, volume_utilization_pct: ?float, distance_to_dock: ?float, reasons: list<string>}>
     */
    public function suggest(Warehouse $warehouse, StockItem $item, string $quantity, int $limit = 5, array $context = []): array
    {
        $quantity = Quantity::of($quantity);
        $context['abc_class'] ??= $item->abc_class;
        $suggestions = [];
        $seen = [];
        $docks = $this->docks($warehouse, (string) ($context['abc_class'] ?? ''));

        foreach ($this->rulesFor($warehouse, $item, $context) as $rule) {
            foreach ($this->rankedBins($this->candidates($warehouse, $rule), $item, (string) $rule->strategy, $docks) as $bin) {
                $this->offer($suggestions, $seen, $bin, $item, $quantity, (string) $rule->id, (string) $rule->strategy, 'rule', $context, $docks);
            }
        }

        if ($suggestions === []) {
            foreach ($this->rankedBins($this->storableBins($warehouse)->get(), $item, 'consolidate', $docks) as $bin) {
                $this->offer($suggestions, $seen, $bin, $item, $quantity, null, 'consolidate', 'fallback', $context, $docks);
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
     * @param  array<string, mixed>  $context
     * @return Collection<int, PutawayRule>
     */
    private function rulesFor(Warehouse $warehouse, StockItem $item, array $context): Collection
    {
        $filters = ['abc_class' => $context['abc_class'] ?? null, 'temperature_control' => $context['temperature_control'] ?? null, 'packaging_unit' => $context['packaging_unit'] ?? null];

        return PutawayRule::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->where(static function (Builder $query) use ($filters): void {
                foreach ($filters as $column => $value) {
                    $query->where(static fn (Builder $match) => $match->whereNull($column)->when($value !== null && $value !== '', static fn (Builder $q) => $q->orWhere($column, $value)));
                }
            })
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
     * @param  list<WarehouseBin>  $docks
     * @return Collection<int, WarehouseBin>
     */
    private function rankedBins(Collection $bins, StockItem $item, string $strategy, array $docks = []): Collection
    {
        if ($bins->isEmpty()) {
            return $bins;
        }

        $binIds = $bins->map(static fn (WarehouseBin $bin): string => (string) $bin->id)->all();
        $holding = StockQuant::query()->whereIn('warehouse_bin_id', $binIds)->where('stock_item_id', $item->id)->where('quantity_on_hand', '>', 0)->pluck('warehouse_bin_id')->map(static fn ($id): string => (string) $id)->flip();
        $occupied = StockQuant::query()->whereIn('warehouse_bin_id', $binIds)->where('quantity_on_hand', '>', 0)->pluck('warehouse_bin_id')->map(static fn ($id): string => (string) $id)->flip();

        return $bins->sortBy(fn (WarehouseBin $bin): array => [
            match ($strategy) {
                'consolidate' => $holding->has((string) $bin->id) ? 0 : ($occupied->has((string) $bin->id) ? 2 : 1),
                'empty_bin' => $occupied->has((string) $bin->id) ? 1 : 0,
                default => 0,
            },
            $this->distanceToDock($bin, $docks) ?? PHP_FLOAT_MAX,
            $bin->pick_sequence ?? PHP_INT_MAX,
            (string) $bin->code,
        ])->values();
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, bool>  $seen
     * @param  array<string, mixed>  $context
     * @param  list<WarehouseBin>  $docks
     */
    private function offer(array &$suggestions, array &$seen, WarehouseBin $bin, StockItem $item, string $quantity, ?string $ruleId, string $strategy, string $source, array $context = [], array $docks = []): void
    {
        if (isset($seen[(string) $bin->id])) {
            return;
        }

        $occupancy = $this->capacity->occupancy([(string) $bin->id])[(string) $bin->id];

        if (! $this->capacity->fits($bin, $item, $quantity, $occupancy)) {
            return;
        }

        $lotId = isset($context['lot_id']) && $context['lot_id'] !== '' ? (string) $context['lot_id'] : null;
        $holds = StockQuant::query()->where('warehouse_bin_id', $bin->id)->where('quantity_on_hand', '>', 0)->get(['stock_item_id', 'lot_id']);

        if (! $bin->allow_mixed_skus && $holds->contains(static fn (StockQuant $quant): bool => (string) $quant->stock_item_id !== (string) $item->id)) {
            return;
        }

        if (! $bin->allow_mixed_lots && $holds->contains(static fn (StockQuant $quant): bool => ($quant->lot_id !== null ? (string) $quant->lot_id : null) !== $lotId)) {
            return;
        }

        $temperature = (string) ($context['temperature_control'] ?? '');
        $zone = $bin->rack?->aisle?->zone;

        if ($temperature !== '' && $zone !== null && (string) ($zone->temperature_control ?? 'ambient') !== $temperature) {
            return;
        }

        $seen[(string) $bin->id] = true;
        $reasons = [$source === 'rule' ? "Matches putaway rule ({$strategy})" : 'Fallback: no putaway rule matched'];

        if ($holds->contains(static fn (StockQuant $quant): bool => (string) $quant->stock_item_id === (string) $item->id && ($lotId === null || (string) $quant->lot_id === $lotId))) {
            $reasons[] = $lotId !== null ? 'Consolidates with the same item and lot' : 'Consolidates with the same item';
        }

        $distance = $this->distanceToDock($bin, $docks);

        if ($distance !== null) {
            $reasons[] = sprintf('%.1f m from the %s dock', $distance, ($context['abc_class'] ?? '') === 'C' ? 'receiving' : 'shipping');
        }

        $suggestions[] = [
            'bin_id' => (string) $bin->id,
            'barcode' => (string) $bin->barcode,
            'code' => (string) $bin->code,
            'full_path_code' => $bin->full_path_code,
            'zone_code' => $zone?->code,
            'bin_type' => (string) ($bin->bin_type ?? 'storage'),
            'rule_id' => $ruleId,
            'strategy' => $strategy,
            'source' => $source,
            'occupancy' => $occupancy,
            'weight_utilization_pct' => $this->utilization($occupancy['weight_kg'], $item->weight_kg, $quantity, $bin->max_weight_kg),
            'volume_utilization_pct' => $this->utilization($occupancy['volume_m3'], $item->volume_m3, $quantity, $bin->max_volume_cbm),
            'distance_to_dock' => $distance !== null ? round($distance, 2) : null,
            'reasons' => $reasons,
        ];
    }

    /**
     * Utilisation of a bin limit after the putaway, in percent; null when the bin declares no limit or the item has no
     * weight/volume to compute with.
     */
    private function utilization(string $held, mixed $unit, string $quantity, mixed $max): ?float
    {
        if ($max === null || $unit === null || (float) $max <= 0.0) {
            return null;
        }

        return round(((float) $held + (float) $quantity * (float) $unit) / (float) $max * 100, 1);
    }

    /**
     * The docks the proximity score measures against: shipping bins for class A (fast movers close to the outbound
     * dock), receiving bins for class C (slow movers close to where stock arrives); none for other classes.
     *
     * @return list<WarehouseBin>
     */
    private function docks(Warehouse $warehouse, string $abcClass): array
    {
        $type = match ($abcClass) {
            'A' => 'shipping',
            'C' => 'receiving',
            default => null,
        };

        if ($type === null) {
            return [];
        }

        return WarehouseBin::query()
            ->where('bin_type', $type)
            ->whereNotNull('coord_x')->whereNotNull('coord_y')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouse->id))
            ->get()
            ->all();
    }

    /**
     * Euclidean distance from the bin to its nearest dock; null when either has no coordinates.
     *
     * @param  list<WarehouseBin>  $docks
     */
    private function distanceToDock(WarehouseBin $bin, array $docks): ?float
    {
        if ($docks === [] || $bin->coord_x === null || $bin->coord_y === null) {
            return null;
        }

        $best = null;

        foreach ($docks as $dock) {
            $distance = sqrt(
                ((float) $bin->coord_x - (float) $dock->coord_x) ** 2
                + ((float) $bin->coord_y - (float) $dock->coord_y) ** 2
                + ((float) ($bin->coord_z ?? 0) - (float) ($dock->coord_z ?? 0)) ** 2
            );
            $best = $best === null ? $distance : min($best, $distance);
        }

        return $best;
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
