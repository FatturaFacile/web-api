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
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class StockController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockService $stock) {}

    /**
     * Current stock per product, optionally restricted to one warehouse, read from the inventory module's quants.
     * Each row keeps `product_id`, `sku`, `name` and `quantity` (stock net of open shortfalls, negative when sales went
     * beyond the stock) and adds `on_hand`, `reserved`, `available` and `shortfall`.
     */
    #[OA\Get(
        path: '/api/v1/inventory/stock-levels',
        operationId: 'getStockLevels',
        description: 'Get current stock per product from the inventory module (stock quants), optionally filtered by warehouse. Each row: product_id, sku, name, quantity (on hand minus open shortfall; negative when sales exceeded the stock), on_hand, reserved, available (on hand minus reserved) and shortfall (quantity sold beyond the stock, not yet settled by incoming stock).',
        summary: 'Get stock levels',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Inventory', 'Stock'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock levels retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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
    #[OA\Post(
        path: '/api/v1/inventory/stock-adjustments',
        operationId: 'recordStockAdjustment',
        description: 'Record manual signed inventory quantity adjustment and valuation write-off/in.',
        summary: 'Record stock adjustment',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Inventory', 'Stock'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'quantity'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'quantity', type: 'number', format: 'float', example: -5),
                    new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Cycle count discrepancy'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock adjusted successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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
