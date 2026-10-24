<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Events\Inventory\StockMovementRecorded;
use App\Jobs\PushStockToEcommerceJob;
use App\Models\EcommerceChannel;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Records stock movements and computes stock levels.
 * Only products with `track_inventory` generate movements, and movements need a warehouse
 * (the primary one by default). Stock may go negative; levels expose it instead of blocking sales.
 */
class StockService
{
    public function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()->orderByDesc('is_primary')->orderBy('created_at')->first();
    }

    /**
     * Record one movement. Quantity is always positive except for `adjustment`, which is signed.
     * Returns null when no warehouse exists.
     */
    public function record(string $productId, string $type, float $quantity, ?Model $reference = null, ?Warehouse $warehouse = null, float $unitCost = 0.0, ?string $notes = null): ?StockMovement
    {
        $warehouse ??= $this->defaultWarehouse();

        if ($warehouse === null) {
            return null;
        }

        $movement = StockMovement::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
        ]);

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
     * Current quantity of one product (in - out + signed adjustments) over all warehouses.
     */
    public function quantityOf(string $productId): float
    {
        $quantity = StockMovement::query()
            ->where('product_id', $productId)
            ->selectRaw("COALESCE(SUM(CASE type WHEN 'in' THEN quantity WHEN 'out' THEN -quantity WHEN 'adjustment' THEN quantity ELSE 0 END), 0) as quantity")
            ->value('quantity');

        return round((float) $quantity, 4);
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
     * Current quantity per product (in - out + signed adjustments), optionally for one warehouse.
     *
     * @return Collection<int, array{product_id: string, sku: ?string, name: ?string, quantity: float}>
     */
    public function levels(?string $warehouseId = null): Collection
    {
        $rows = StockMovement::query()
            ->selectRaw("product_id, SUM(CASE type WHEN 'in' THEN quantity WHEN 'out' THEN -quantity WHEN 'adjustment' THEN quantity ELSE 0 END) as quantity")
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->get();

        $products = Product::withTrashed()->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');

        return $rows->map(fn ($row): array => [
            'product_id' => $row->product_id,
            'sku' => $products[$row->product_id]->sku ?? null,
            'name' => $products[$row->product_id]->name ?? null,
            'quantity' => round((float) $row->quantity, 4),
        ])->values();
    }
}
