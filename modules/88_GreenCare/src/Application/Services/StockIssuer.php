<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Database\Eloquent\Model;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;

/**
 * Takes the plants, materials and phytosanitary products of the crews out of the warehouses of the inventory (the one stock truth of the ERP).
 */
final class StockIssuer
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @return array{product: Product, warehouse_id: string, movement_id: string|null}
     *
     * @throws GreenCareException warehouse_required when no warehouse exists
     */
    public function issue(string $productId, float $quantity, ?string $warehouseId, Model $reference, ?string $notes = null): array
    {
        $product = Product::query()->findOrFail($productId);
        $warehouse = $warehouseId !== null ? Warehouse::query()->findOrFail($warehouseId) : $this->stock->defaultWarehouse();

        if ($warehouse === null) {
            throw GreenCareException::because('warehouse_required');
        }

        $movement = $this->stock->record($productId, 'out', $quantity, $reference, $warehouse, (float) $product->cost_price, $notes);

        return ['product' => $product, 'warehouse_id' => (string) $warehouse->id, 'movement_id' => $movement === null ? null : (string) $movement->id];
    }
}
