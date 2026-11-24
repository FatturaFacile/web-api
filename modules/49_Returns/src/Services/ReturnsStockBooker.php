<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Database\Eloquent\Model;
use Modules\Returns\Support\ReturnsError;

/**
 * Books the stock movements of the returns through the stock service of the inventory module, the one stock truth of
 * the application. Only products that track inventory move stock; a missing warehouse is refused instead of dropped.
 */
final readonly class ReturnsStockBooker
{
    public function __construct(private StockService $stock) {}

    /**
     * The warehouse named by the caller, else the default one of the company; the field is reported when none exists.
     */
    public function warehouse(string $tenantId, ?string $warehouseId, string $field = 'warehouse_id'): Warehouse
    {
        $warehouse = $warehouseId !== null
            ? Warehouse::query()->where('tenant_id', $tenantId)->whereKey($warehouseId)->first()
            : $this->stock->defaultWarehouse();

        if ($warehouse === null) {
            throw ReturnsError::on($field, 'stock.warehouse_required');
        }

        return $warehouse;
    }

    /**
     * Goods come into the warehouse (a customer return). Returns the movement id, or null when the product does not
     * track inventory.
     */
    public function receive(string $productId, string $quantity, Warehouse $warehouse, Model $reference, string $note): ?string
    {
        return $this->book($productId, 'in', $quantity, $warehouse, $reference, $note);
    }

    /**
     * Goods leave the warehouse (a replacement or a return to the supplier). Returns the movement id, or null when the
     * product does not track inventory.
     */
    public function issue(string $productId, string $quantity, Warehouse $warehouse, Model $reference, string $note): ?string
    {
        return $this->book($productId, 'out', $quantity, $warehouse, $reference, $note);
    }

    private function book(string $productId, string $type, string $quantity, Warehouse $warehouse, Model $reference, string $note): ?string
    {
        $product = Product::withTrashed()->find($productId);

        if ($product === null || ! $product->track_inventory) {
            return null;
        }

        $movement = $this->stock->record($productId, $type, (float) $quantity, $reference, $warehouse, (float) $product->cost_price, $note);

        return $movement !== null ? (string) $movement->getKey() : null;
    }
}
