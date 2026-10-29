<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\CreateStockItemAction;
use Modules\Inventory\DTOs\CreateStockItemDTO;
use Modules\Inventory\Http\Requests\StoreStockItemRequest;
use Modules\Inventory\Http\Requests\UpdateStockItemRequest;
use Modules\Inventory\Http\Resources\StockItemResource;
use Modules\Inventory\Models\StockItem;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Stock Items', description: 'Product catalog, SKU master data, standard costs, and stock levels')]
final class StockItemController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/items',
        summary: 'List stock items',
        description: 'Retrieve paginated list of catalog stock items with search and category filters.',
        tags: ['Inventory Stock Items'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tracking_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['none', 'lot', 'serial'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated stock items'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List stock items', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockItem::class);

        $query = StockItem::with(['category'])
            ->withSum('quants', 'quantity_on_hand')
            ->withSum('quants', 'quantity_reserved')
            ->orderBy('sku');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->filled('tracking_type')) {
            $query->where('tracking_type', $request->query('tracking_type'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', $search)
                    ->orWhere('name', 'like', $search)
                    ->orWhere('barcode_ean13', 'like', $search);
            });
        }

        $items = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(StockItemResource::collection($items)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/items',
        summary: 'Create a stock item',
        description: 'Creates a new stock item with tracking policies and standard pricing.',
        tags: ['Inventory Stock Items'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category_id', 'sku', 'name', 'uom', 'tracking_type'],
                properties: [
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'sku', type: 'string', example: 'PMP-IND-01'),
                    new OA\Property(property: 'name', type: 'string', example: 'Industrial Water Pump 500W'),
                    new OA\Property(property: 'uom', type: 'string', example: 'PCE'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'barcode_ean13', type: 'string', example: '8001234567890'),
                    new OA\Property(property: 'tracking_type', type: 'string', enum: ['none', 'lot', 'serial']),
                    new OA\Property(property: 'standard_cost', type: 'number', example: 120.50),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock item created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a stock item', tags: ['Inventory'])]
    public function store(StoreStockItemRequest $request, CreateStockItemAction $action): JsonResponse
    {
        $this->authorize('create', StockItem::class);

        $dto = new CreateStockItemDTO(
            categoryId: $request->string('category_id')->value(),
            sku: $request->string('sku')->value(),
            name: $request->string('name')->value(),
            uom: $request->string('uom', 'PCE')->value(),
            description: $request->input('description'),
            barcodeEan13: $request->input('barcode_ean13'),
            barcodeCustom: $request->input('barcode_custom'),
            trackingType: $request->string('tracking_type', 'none')->value(),
            standardCost: (float) $request->input('standard_cost', 0.0),
            movingAverageCost: (float) $request->input('moving_average_cost', 0.0),
            weightKg: $request->filled('weight_kg') ? (float) $request->input('weight_kg') : null,
            isActive: $request->boolean('is_active', true)
        );

        $item = $action->execute($dto);

        return response()->json(new StockItemResource($item->load('category')), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/{id}',
        summary: 'Get stock item details',
        description: 'Retrieve detailed SKU information including current quants and reorder rules.',
        tags: ['Inventory Stock Items'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock item details'),
            new OA\Response(response: 404, description: 'Stock item not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get stock item details', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        $item = StockItem::with(['category', 'quants.warehouse', 'quants.bin', 'reorderRules'])->findOrFail($id);
        $this->authorize('view', $item);

        return response()->json(new StockItemResource($item));
    }

    #[OA\Put(
        path: '/api/v1/inventory/items/{id}',
        summary: 'Update stock item',
        description: 'Updates SKU properties, pricing or tracking settings.',
        tags: ['Inventory Stock Items'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock item updated'),
            new OA\Response(response: 404, description: 'Stock item not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update stock item', tags: ['Inventory'])]
    public function update(string $id, UpdateStockItemRequest $request): JsonResponse
    {
        $item = StockItem::findOrFail($id);
        $this->authorize('update', $item);

        $item->update($request->validated());

        return response()->json(new StockItemResource($item->fresh(['category'])));
    }

    #[OA\Delete(
        path: '/api/v1/inventory/items/{id}',
        summary: 'Delete stock item',
        description: 'Deactivates or deletes an unused stock item.',
        tags: ['Inventory Stock Items'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Stock item deleted'),
            new OA\Response(response: 404, description: 'Stock item not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Delete stock item', tags: ['Inventory'])]
    public function destroy(string $id): JsonResponse
    {
        $item = StockItem::findOrFail($id);
        $this->authorize('delete', $item);

        $item->delete();

        return response()->json(null, 204);
    }
}
