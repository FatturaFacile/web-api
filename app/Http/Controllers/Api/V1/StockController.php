<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Retail\StockAdjustmentRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StockController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockService $stock) {}

    /**
     * Current stock per product, optionally restricted to one warehouse.
     */
    public function levels(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->stock->levels($request->query('warehouse_id'))->all(),
            __('stock.messages.levels')
        );
    }

    /**
     * Manual signed stock correction (inventory count result).
     */
    public function adjust(StockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $product = Product::findOrFail($data['product_id']);

        if (! $product->track_inventory) {
            throw ValidationException::withMessages(['product_id' => __('stock.not_tracked')]);
        }

        if ((float) $data['quantity'] > 0) {
            $this->stock->assertReceivable($product);
        }

        $warehouse = isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : null;
        $movement = $this->stock->record($product->id, 'adjustment', (float) $data['quantity'], null, $warehouse, (float) $product->cost_price, $data['notes'] ?? __('stock.adjustment_note'));

        if ($movement === null) {
            throw ValidationException::withMessages(['warehouse_id' => __('stock.no_warehouse')]);
        }

        return $this->successResponse(new CatalogResource($movement), __('stock.messages.adjusted'), Response::HTTP_CREATED);
    }
}
