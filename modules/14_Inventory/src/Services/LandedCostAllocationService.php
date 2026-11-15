<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Contracts\LandedCostAllocationServiceInterface;
use Modules\Inventory\DTOs\LandedCostAllocationLine;
use Modules\Inventory\Enums\LandedCostAllocationKey;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Support\Decimal;

/**
 * Landed cost allocation engine (FatturaFacile/FatturaFacile#144).
 *
 * Targets are the cost layers created by goods receipt movements (`receipt_id`), one per
 * receipt / item / lot. Bases: value = accepted qty * layer unit cost, weight = qty * weight_kg,
 * volume = qty * volume_m3, quantity = qty.
 */
final class LandedCostAllocationService implements LandedCostAllocationServiceInterface
{
    public function __construct(
        private readonly InventoryCostingServiceInterface $costing
    ) {}

    public function allocate(LandedCostAllocationKey $key, string $totalAmount, array $receiptIds): array
    {
        $receiptIds = array_values(array_unique($receiptIds));

        /** @var Collection<int, InventoryCostLayer> $layers */
        $layers = InventoryCostLayer::query()
            ->with('item.category')
            ->whereIn('receipt_id', $receiptIds)
            ->where('source_type', 'receipt')
            ->orderBy('received_at')
            ->orderBy('layer_sequence')
            ->get()
            ->filter(static fn (InventoryCostLayer $layer): bool => $layer->item !== null)
            ->values();

        $missingReceipts = array_values(array_diff($receiptIds, $layers->pluck('receipt_id')->map(static fn ($id): string => (string) $id)->all()));
        if ($missingReceipts !== []) {
            throw ValidationException::withMessages([
                'receipt_ids' => ['The following goods receipts have no inventory receipt layers to allocate to: '.implode(', ', $missingReceipts).'.'],
            ]);
        }

        $this->assertPhysicalBasisAvailable($key, $layers);

        $weights = [];
        foreach ($layers as $layer) {
            $weights[(string) $layer->id] = $this->basis($key, $layer);
        }

        $sum = array_reduce($weights, static fn (string $carry, string $w): string => Decimal::add($carry, $w), '0');
        if (! Decimal::isPositive($sum)) {
            throw ValidationException::withMessages([
                'allocation_key' => ["No target receipt line has a basis greater than zero for allocation key [{$key->value}]."],
            ]);
        }

        $parts = Decimal::allocate($totalAmount, $weights);

        $lines = [];
        foreach ($layers as $layer) {
            /** @var StockItem $item */
            $item = $layer->item;
            $allocated = $parts[(string) $layer->id];
            $method = $this->costing->resolveEffectiveMethod($item);
            [$toInventory, $toCogs, $toVariance] = $this->split($method, $layer, $item, $allocated);

            $lines[] = new LandedCostAllocationLine(
                receiptId: (string) $layer->receipt_id,
                costLayerId: (string) $layer->id,
                itemId: (string) $item->id,
                lotId: $layer->lot_id !== null ? (string) $layer->lot_id : null,
                method: $method,
                basisValue: Decimal::round($weights[(string) $layer->id], 6),
                allocatedAmount: $allocated,
                toInventoryAmount: $toInventory,
                toCogsAmount: $toCogs,
                toVarianceAmount: $toVariance,
            );
        }

        return $lines;
    }

    private function basis(LandedCostAllocationKey $key, InventoryCostLayer $layer): string
    {
        $quantity = (string) $layer->original_quantity;
        /** @var StockItem $item */
        $item = $layer->item;

        return match ($key) {
            LandedCostAllocationKey::Value => Decimal::mul($quantity, (string) $layer->unit_cost),
            LandedCostAllocationKey::Weight => Decimal::mul($quantity, (string) $item->weight_kg),
            LandedCostAllocationKey::Volume => Decimal::mul($quantity, (string) $item->volume_m3),
            LandedCostAllocationKey::Quantity => Decimal::of($quantity),
        };
    }

    /**
     * @param  Collection<int, InventoryCostLayer>  $layers
     */
    private function assertPhysicalBasisAvailable(LandedCostAllocationKey $key, Collection $layers): void
    {
        $column = match ($key) {
            LandedCostAllocationKey::Weight => 'weight_kg',
            LandedCostAllocationKey::Volume => 'volume_m3',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $offending = $layers
            ->map(static fn (InventoryCostLayer $layer): ?StockItem => $layer->item)
            ->filter(static fn (?StockItem $item): bool => $item !== null && ! Decimal::isPositive((string) $item->getAttribute($column)))
            ->map(static fn (StockItem $item): string => (string) $item->sku)
            ->unique()
            ->values()
            ->all();

        if ($offending !== []) {
            throw ValidationException::withMessages([
                'allocation_key' => ["Allocation by {$key->value} requires {$column} on every item; missing for: ".implode(', ', $offending).'.'],
                'items' => $offending,
            ]);
        }
    }

    /**
     * Splits an allocated amount between inventory (units still on hand), COGS (units already sold)
     * and purchase price variance (standard-cost items keep inventory at standard).
     *
     * @return array{0: string, 1: string, 2: string} [to_inventory, to_cogs, to_variance]
     */
    private function split(ValuationMethod $method, InventoryCostLayer $layer, StockItem $item, string $allocated): array
    {
        $original = (string) $layer->original_quantity;
        if (! Decimal::isPositive($original)) {
            return ['0.00', $allocated, '0.00'];
        }

        if ($method === ValuationMethod::WeightedAverage) {
            $onHand = Decimal::quantity((string) StockQuant::query()->where('stock_item_id', $item->id)->sum('quantity_on_hand'));
            if (! Decimal::isPositive($onHand)) {
                return ['0.00', $allocated, '0.00'];
            }
        }

        // Layers are depleted FIFO for every method, so consumed quantity is the exact sold share of this receipt.
        $sold = Decimal::min(Decimal::max('0', Decimal::sub($original, (string) $layer->remaining_quantity)), $original);

        $toCogs = Decimal::money(Decimal::div(Decimal::mul($allocated, $sold), $original));
        $remainder = Decimal::money(Decimal::sub($allocated, $toCogs));

        if ($method === ValuationMethod::StandardCost) {
            return ['0.00', $toCogs, $remainder];
        }

        return [$remainder, $toCogs, '0.00'];
    }
}
