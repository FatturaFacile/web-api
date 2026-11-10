<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DeliveryNote;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class InventoryController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/inventory/products',
        operationId: 'getInventoryProducts',
        description: 'List paginated inventory products with associated brands and parent categories.',
        summary: 'List products',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Inventory'],
        responses: [
            new OA\Response(response: 200, description: 'Products retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function products(Request $request): JsonResponse
    {
        $products = Product::with(['brand', 'parent'])->latest()->paginate(25);

        return $this->successResponse(data: $products, message: 'Products retrieved');
    }

    #[OA\Post(
        path: '/api/v1/inventory/products',
        operationId: 'createInventoryProduct',
        description: 'Create a new catalog product with SKU, barcode, pricing, and tax rates.',
        summary: 'Create product',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Inventory'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku', 'name', 'sale_price', 'tax_rate'],
                properties: [
                    new OA\Property(property: 'sku', type: 'string', example: 'PROD-001'),
                    new OA\Property(property: 'name', type: 'string', example: 'Standard Widget'),
                    new OA\Property(property: 'barcode', type: 'string', nullable: true, example: '8001234567890'),
                    new OA\Property(property: 'sale_price', type: 'number', format: 'float', example: 49.90),
                    new OA\Property(property: 'cost_price', type: 'number', format: 'float', nullable: true, example: 25.00),
                    new OA\Property(property: 'tax_rate', type: 'number', format: 'float', example: 22.0),
                    new OA\Property(property: 'track_inventory', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Product created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeProduct(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return $this->successResponse(
            data: $product,
            message: 'Product created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Put(
        path: '/api/v1/inventory/products/{id}',
        operationId: 'updateInventoryProduct',
        description: 'Update existing product pricing, SKU, or attributes.',
        summary: 'Update product',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product updated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Product not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateProduct(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update($request->validated());

        return $this->successResponse(data: $product->refresh(), message: __('inventory.messages.product_updated'));
    }

    public function warehouses(): JsonResponse
    {
        $warehouses = Warehouse::all();

        return $this->successResponse(data: $warehouses, message: 'Warehouses retrieved');
    }

    public function movements(Request $request): JsonResponse
    {
        $movements = StockMovement::latest()->paginate(25);

        return $this->successResponse(data: $movements, message: 'Stock movements retrieved');
    }

    #[OA\Get(
        path: '/api/v1/inventory/delivery-notes',
        operationId: 'getInventoryDeliveryNotes',
        description: 'List transport delivery notes (Documenti di Trasporto - DDT) for logistics.',
        summary: 'List delivery notes',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Inventory'],
        responses: [
            new OA\Response(response: 200, description: 'Delivery notes retrieved (the paginator is inside data: rows in data.data)', content: new OA\JsonContent(ref: '#/components/schemas/PurchasesDeliveryNotesListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function deliveryNotes(Request $request): JsonResponse
    {
        $ddts = DeliveryNote::latest('date')->paginate(25);

        return $this->successResponse(data: $ddts, message: 'Delivery notes (DDT) retrieved');
    }
}
