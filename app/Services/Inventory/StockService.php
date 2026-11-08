<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Events\Inventory\StockMovementRecorded;
use App\Jobs\PushStockToEcommerceJob;
use App\Models\EcommerceChannel;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\ProductStockLedger;

/**
 * Records stock movements of catalog products and reads their stock levels, on the inventory module's ledger
 * (ProductStockLedger): one stock truth for the app's documents and the module's receipts, transfers and reservations.
 * Only products with `track_inventory` generate movements, and movements need a warehouse (the primary one by
 * default). A sale is never refused for missing stock: what the warehouse lacks stays open as a shortfall and the level
 * shows it as negative stock (see ProductStockLedger for the policy).
 */
class StockService
{
    public function __construct(private readonly ProductStockLedger $ledger) {}

    public function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()->orderByDesc('is_primary')->orderBy('created_at')->first();
    }

    /**
     * Record one movement. Quantity is always positive except for `adjustment`, which is signed; a negative `in` or
     * `out` (a document line with a negative quantity) moves the other way. Returns null when no warehouse exists or
     * the quantity is zero.
     *
     * The movement is the module's double-entry movement (stock item, bin, quant update under lock) carrying the app
     * columns (product, type, reference). Its quantity is unsigned like every module movement: the direction is given
     * by the source and destination bins, and `signed_quantity` gives the app's signed view of it.
     */
    public function record(string $productId, string $type, float $quantity, ?Model $reference = null, ?Warehouse $warehouse = null, float $unitCost = 0.0, ?string $notes = null): ?StockMovement
    {
        $warehouse ??= $this->defaultWarehouse();

        if ($warehouse === null || round($quantity, 4) === 0.0) {
            return null;
        }

        $product = Product::withTrashed()->findOrFail($productId);
        $inbound = match ($type) {
            'out' => $quantity < 0,
            default => $quantity > 0,
        };
        $context = [
            'movement_type' => $this->movementTypeOf($type, $inbound, $reference),
            'app_type' => $type === 'adjustment' ? 'adjustment' : ($inbound ? 'in' : 'out'),
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey() !== null ? (string) $reference->getKey() : null,
            'unit_cost' => $unitCost,
            'notes' => $notes,
        ];

        $moduleMovement = DB::transaction(fn () => $inbound
            ? $this->ledger->receive($product, (string) $warehouse->id, abs($quantity), $context)
            : $this->ledger->issue($product, (string) $warehouse->id, abs($quantity), $context));

        $movement = StockMovement::query()->findOrFail($moduleMovement->id);

        $this->queueEcommerceStockPush($movement);
        StockMovementRecorded::dispatch($movement);

        return $movement;
    }

    /**
     * Stock cannot be loaded manually or through purchases into a variant that has been deactivated (for instance at
     * the end of its series); it has to be reactivated explicitly first.
     *
     * @throws ValidationException
     */
    public function assertReceivable(Product $product, string $field = 'product_id'): void
    {
        if ($product->is_variant && ! $product->is_active) {
            throw ValidationException::withMessages([$field => __('inventory.variant_inactive', ['sku' => $product->sku])]);
        }
    }

    /**
     * Current stock of one product over all warehouses: on hand in the module's quants minus the quantity sold beyond
     * the stock and not yet settled (so it can be negative, as it always could).
     */
    public function quantityOf(string $productId): float
    {
        return (float) ($this->ledger->levels(null, [$productId])->first()['quantity'] ?? 0.0);
    }

    /**
     * Tell the shops about the new quantity, once the surrounding transaction has been committed, when the tenant has
     * an active channel with stock synchronisation.
     */
    private function queueEcommerceStockPush(StockMovement $movement): void
    {
        $hasChannel = EcommerceChannel::query()
            ->where('tenant_id', $movement->tenant_id)
            ->where('is_active', true)
            ->where('sync_stock_enabled', true)
            ->exists();

        if ($hasChannel) {
            PushStockToEcommerceJob::dispatch($movement->tenant_id, $movement->product_id)->afterCommit();
        }
    }

    /**
     * The module's movement type for an app movement, which the module's analytics read (consumption for safety stock
     * and ABC classes, receipts for FIFO valuation).
     */
    private function movementTypeOf(string $type, bool $inbound, ?Model $reference): string
    {
        return match (true) {
            $type === 'adjustment' => $inbound ? 'adjustment_in' : 'adjustment_out',
            $inbound && $reference instanceof GoodsReceipt => 'supplier_receipt',
            $inbound && $reference !== null => 'customer_return',
            $inbound => 'receipt',
            default => 'customer_delivery',
        };
    }

    /**
     * Record a movement per document line for inventory-tracked products.
     *
     * @param  iterable<int, Model>  $lines  models exposing `product_id` and `quantity`
     * @return int number of movements recorded
     */
    public function recordForLines(string $type, Model $reference, iterable $lines, ?Warehouse $warehouse = null): int
    {
        $lines = collect($lines)->filter(fn (Model $line): bool => $line->product_id !== null);
        $tracked = Product::whereIn('id', $lines->pluck('product_id')->unique())->where('track_inventory', true)->pluck('cost_price', 'id');
        $count = 0;

        foreach ($lines as $line) {
            if (! $tracked->has($line->product_id)) {
                continue;
            }

            $cost = (float) ($line->getAttribute('unit_cost') ?? $tracked[$line->product_id]);

            if ($this->record($line->product_id, $type, (float) $line->quantity, $reference, $warehouse, $cost) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Current stock per product from the module's quants, optionally for one warehouse. `quantity` keeps its meaning
     * (stock net of what was sold beyond it, possibly negative); `on_hand`, `reserved`, `available` and `shortfall` are
     * the module's figures behind it.
     *
     * @return Collection<int, array{product_id: string, sku: ?string, name: ?string, quantity: float, on_hand: float, reserved: float, available: float, shortfall: float}>
     */
    public function levels(?string $warehouseId = null): Collection
    {
        $rows = $this->ledger->levels($warehouseId);
        $products = Product::withTrashed()->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');

        return $rows
            ->filter(fn (array $row): bool => $products->has($row['product_id']))
            ->map(fn (array $row): array => [
                'product_id' => $row['product_id'],
                'sku' => $products[$row['product_id']]->sku ?? null,
                'name' => $products[$row['product_id']]->name ?? null,
                'quantity' => $row['quantity'],
                'on_hand' => $row['on_hand'],
                'reserved' => $row['reserved'],
                'available' => $row['available'],
                'shortfall' => $row['shortfall'],
            ])->values();
    }
}
