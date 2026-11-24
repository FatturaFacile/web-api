<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockShortfall;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * Stock of catalog products, kept by the inventory module: the one stock truth for the till, the delivery notes, the
 * app's purchase receipts and stock adjustments, the e-commerce orders and the module's own receipts and transfers.
 *
 * A product moves stock through its stock item (ProductStockItemResolver) in a warehouse's default bin
 * (WarehouseDefaultBinResolver), with the module's double-entry movements: quants change under row locks and every
 * movement is in `stock_movements` with the product, the app movement type and the app document it belongs to.
 *
 * Negative stock policy. The module never lets a quant go below zero (database check), while the app has always
 * accepted a sale beyond the stock and shown negative stock instead of blocking the till. Both hold: an issue takes
 * what is available (on hand minus reserved) from the warehouse's quants, books a `negative_stock_cover` movement for
 * the missing part, issues the full quantity and keeps the missing part open as a StockShortfall. The next stock that
 * comes into the warehouse settles open shortfalls first (SettleStockShortfallsListener), with a
 * `negative_stock_settlement` movement. Levels show the open part as negative stock. No quantity is ever dropped.
 */
final readonly class ProductStockLedger
{
    public const string COVER_MOVEMENT_TYPE = 'negative_stock_cover';

    public const string SETTLEMENT_MOVEMENT_TYPE = 'negative_stock_settlement';

    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private ProductStockItemResolver $stockItems,
        private WarehouseDefaultBinResolver $bins,
        private OutboundIssuePreference $preference,
    ) {}

    /**
     * Book stock of a product into the warehouse's default bin.
     *
     * @param  array{movement_type: string, app_type: string, reference_type?: ?string, reference_id?: ?string, unit_cost?: ?float, notes?: ?string, executed_at?: ?string}  $context
     */
    public function receive(Product $product, string $warehouseId, float $quantity, array $context): StockMovement
    {
        return DB::transaction(function () use ($product, $warehouseId, $quantity, $context): StockMovement {
            $item = $this->stockItems->resolve($product);
            $bin = $this->bins->resolve($warehouseId);

            $movement = $this->movements->executeTransfer($this->transfer($item, null, $bin->id, $quantity, $context['movement_type'], null, $context));
            $this->annotate($movement, $product, $context['app_type'], $context);

            return $movement->refresh();
        });
    }

    /**
     * Take stock of a product out of a warehouse, never refusing for missing stock (see the class description).
     * Bins preferred by the caller (OutboundIssuePreference, e.g. the shipping bin the goods were picked into) go
     * first, then the default bin, then the other bins; quants without a lot before lots, oldest first. Stock that is
     * not available to orders is never taken: bins of quarantine or transit zones and quarantined lots stay untouched.
     * Returns the movement that took the largest share, the one the caller reports.
     *
     * @param  array{movement_type: string, app_type: string, reference_type?: ?string, reference_id?: ?string, unit_cost?: ?float, notes?: ?string, executed_at?: ?string}  $context
     */
    public function issue(Product $product, string $warehouseId, float $quantity, array $context): StockMovement
    {
        return DB::transaction(function () use ($product, $warehouseId, $quantity, $context): StockMovement {
            $item = $this->stockItems->resolve($product);
            $defaultBin = $this->bins->resolve($warehouseId);
            $binIds = $this->bins->issuableBins($warehouseId)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            $preferred = array_flip($this->preference->preferredBinIds());

            /** @var Collection<int, StockQuant> $quants */
            $quants = StockQuant::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('stock_item_id', $item->id)
                ->whereIn('warehouse_bin_id', $binIds)
                ->where(static fn ($lot) => $lot->whereNull('lot_id')->orWhereNotIn('lot_id', StockLot::withoutGlobalScopes()->where('is_quarantined', true)->select('id')))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->sortBy(static fn (StockQuant $quant): array => [
                    $preferred[(string) $quant->warehouse_bin_id] ?? PHP_INT_MAX,
                    $quant->warehouse_bin_id === $defaultBin->id ? 0 : 1,
                    $quant->lot_id === null ? 0 : 1,
                    (string) $quant->created_at,
                ])
                ->values();

            $remaining = Quantity::of($quantity);
            /** @var list<array{bin: string, lot: ?string, quantity: string}> $legs */
            $legs = [];

            foreach ($quants as $quant) {
                if (! Quantity::isPositive($remaining)) {
                    break;
                }

                $take = Quantity::min(StockAllocationPlanner::available($quant), $remaining);

                if (! Quantity::isPositive($take)) {
                    continue;
                }

                $legs[] = ['bin' => (string) $quant->warehouse_bin_id, 'lot' => $quant->lot_id, 'quantity' => $take];
                $remaining = Quantity::sub($remaining, $take);
            }

            $cover = null;

            if (Quantity::isPositive($remaining)) {
                $cover = $this->movements->executeTransfer($this->transfer($item, null, $defaultBin->id, (float) $remaining, self::COVER_MOVEMENT_TYPE, null, $context));
                $this->annotate($cover, $product, 'in', $context);

                $merged = false;
                foreach ($legs as $index => $leg) {
                    if ($leg['bin'] === $defaultBin->id && $leg['lot'] === null) {
                        $legs[$index]['quantity'] = Quantity::add($leg['quantity'], $remaining);
                        $merged = true;
                    }
                }

                if (! $merged) {
                    $legs[] = ['bin' => $defaultBin->id, 'lot' => null, 'quantity' => $remaining];
                }
            }

            $main = null;
            $mainQuantity = '0';

            foreach ($legs as $leg) {
                $movement = $this->movements->executeTransfer($this->transfer($item, $leg['bin'], null, (float) $leg['quantity'], $context['movement_type'], $leg['lot'], $context));
                $this->annotate($movement, $product, $context['app_type'], $context);

                if (Quantity::compare($leg['quantity'], $mainQuantity) > 0) {
                    $main = $movement;
                    $mainQuantity = $leg['quantity'];
                }
            }

            if ($cover !== null) {
                $shortfall = new StockShortfall([
                    'company_id' => $item->company_id,
                    'stock_item_id' => $item->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'warehouse_bin_id' => $defaultBin->id,
                    'quantity' => $remaining,
                    'quantity_open' => $remaining,
                    'cover_movement_id' => $cover->id,
                    'origin_document_type' => $context['reference_type'] ?? null,
                    'origin_document_id' => $context['reference_id'] ?? null,
                ]);
                $shortfall->tenant_id = $item->tenant_id;
                $shortfall->save();
            }

            /** @var StockMovement $main */
            return $main->refresh();
        });
    }

    /**
     * Settle the open shortfalls of the item in the bin's warehouse with stock that just came into that bin, oldest
     * shortfall first. Runs inside the movement's transaction; returns the settled quantity.
     */
    public function settleShortfalls(StockItem $item, WarehouseBin $bin, string $warehouseId, ?string $lotId): float
    {
        $shortfalls = StockShortfall::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('stock_item_id', $item->id)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_open', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($shortfalls->isEmpty()) {
            return 0.0;
        }

        $quant = StockQuant::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('warehouse_bin_id', $bin->id)
            ->where('stock_item_id', $item->id)
            ->where('lot_id', $lotId)
            ->lockForUpdate()
            ->first();

        $available = $quant === null ? '0' : StockAllocationPlanner::available($quant);
        $settled = '0';

        foreach ($shortfalls as $shortfall) {
            if (! Quantity::isPositive($available)) {
                break;
            }

            $take = Quantity::min((string) $shortfall->quantity_open, $available);
            $context = [
                'reference_type' => $shortfall->origin_document_type,
                'reference_id' => $shortfall->origin_document_id,
                'notes' => 'Settles the stock sold beyond availability (shortfall '.$shortfall->id.').',
            ];

            $movement = $this->movements->executeTransfer($this->transfer($item, $bin->id, null, (float) $take, self::SETTLEMENT_MOVEMENT_TYPE, $lotId, $context));

            if ($shortfall->product_id !== null) {
                StockMovement::withoutGlobalScopes()->whereKey($movement->id)->update([
                    'product_id' => $shortfall->product_id,
                    'type' => 'out',
                    'reference_type' => $shortfall->origin_document_type,
                    'reference_id' => $shortfall->origin_document_id,
                    'notes' => $context['notes'],
                ]);
            }

            $open = Quantity::sub((string) $shortfall->quantity_open, $take);
            StockShortfall::withoutGlobalScopes()->whereKey($shortfall->id)->update([
                'quantity_open' => $open,
                'settled_at' => Quantity::isPositive($open) ? null : now(),
                'updated_at' => now(),
            ]);

            $available = Quantity::sub($available, $take);
            $settled = Quantity::add($settled, $take);
        }

        return (float) $settled;
    }

    /**
     * Stock per product from the quants, optionally of one warehouse and of some products only. `quantity` is the
     * stock net of open shortfalls (negative when sales went beyond the stock), `on_hand`, `reserved` and `available`
     * are the module's figures, `shortfall` the quantity sold beyond the stock and not yet settled.
     *
     * @param  list<string>|null  $productIds
     * @return Collection<int, array{product_id: string, quantity: float, on_hand: float, reserved: float, available: float, shortfall: float}>
     */
    public function levels(?string $warehouseId = null, ?array $productIds = null): Collection
    {
        $quants = StockQuant::query()
            ->join('stock_items', 'stock_items.id', '=', 'stock_quants.stock_item_id')
            ->join('warehouse_bins', 'warehouse_bins.id', '=', 'stock_quants.warehouse_bin_id')
            ->join('warehouse_racks', 'warehouse_racks.id', '=', 'warehouse_bins.warehouse_rack_id')
            ->join('warehouse_aisles', 'warehouse_aisles.id', '=', 'warehouse_racks.warehouse_aisle_id')
            ->join('warehouse_zones', 'warehouse_zones.id', '=', 'warehouse_aisles.warehouse_zone_id')
            ->whereNotNull('stock_items.product_id')
            ->whereNull('stock_items.deleted_at')
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_zones.warehouse_id', $warehouseId))
            ->when($productIds !== null, static fn ($query) => $query->whereIn('stock_items.product_id', $productIds))
            ->groupBy('stock_items.product_id')
            ->selectRaw('stock_items.product_id, SUM(stock_quants.quantity_on_hand) AS on_hand, SUM(stock_quants.quantity_reserved) AS reserved')
            ->toBase()
            ->get()
            ->keyBy('product_id');

        $shortfalls = StockShortfall::query()
            ->whereNotNull('product_id')
            ->where('quantity_open', '>', 0)
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($productIds !== null, static fn ($query) => $query->whereIn('product_id', $productIds))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_open) AS open')
            ->toBase()
            ->get()
            ->keyBy('product_id');

        return $quants->keys()->merge($shortfalls->keys())->unique()->values()
            ->map(static function ($productId) use ($quants, $shortfalls): array {
                $onHand = round((float) ($quants[$productId]->on_hand ?? 0), 4);
                $reserved = round((float) ($quants[$productId]->reserved ?? 0), 4);
                $shortfall = round((float) ($shortfalls[$productId]->open ?? 0), 4);

                return [
                    'product_id' => (string) $productId,
                    'quantity' => round($onHand - $shortfall, 4),
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'available' => round(max(0.0, $onHand - $reserved), 4),
                    'shortfall' => $shortfall,
                ];
            });
    }

    /**
     * @param  array{reference_type?: ?string, reference_id?: ?string, unit_cost?: ?float, notes?: ?string, executed_at?: ?string}  $context
     */
    private function transfer(StockItem $item, ?string $sourceBinId, ?string $destinationBinId, float $quantity, string $movementType, ?string $lotId, array $context): StockTransferDTO
    {
        $unitCost = $context['unit_cost'] ?? null;

        return new StockTransferDTO(
            stockItemId: (string) $item->id,
            sourceBinId: $sourceBinId,
            destinationBinId: $destinationBinId,
            quantity: $quantity,
            movementType: $movementType,
            lotId: $lotId,
            originDocumentType: $context['reference_type'] ?? null,
            originDocumentId: $context['reference_id'] ?? null,
            customUnitCost: $unitCost !== null && $unitCost > 0.0 ? $unitCost : null,
            notes: $context['notes'] ?? null,
            executedAt: $context['executed_at'] ?? null,
        );
    }

    /**
     * Give the module movement the app columns (product, app type, app document), so the app's movement list and the
     * per-document lookups keep working on the single ledger.
     *
     * @param  array{reference_type?: ?string, reference_id?: ?string, notes?: ?string}  $context
     */
    private function annotate(StockMovement $movement, Product $product, string $appType, array $context): void
    {
        StockMovement::withoutGlobalScopes()->whereKey($movement->id)->update([
            'product_id' => $product->id,
            'type' => $appType,
            'reference_type' => $context['reference_type'] ?? null,
            'reference_id' => $context['reference_id'] ?? null,
        ]);
    }
}
