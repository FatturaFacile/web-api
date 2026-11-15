<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\ChangeValuationMethodAction;
use Modules\Inventory\Http\Requests\UpdateCategoryValuationRequest;
use Modules\Inventory\Http\Requests\UpdateItemValuationRequest;
use Modules\Inventory\Http\Resources\ValuationCategoryResource;
use Modules\Inventory\Http\Resources\ValuationConfigResource;
use Modules\Inventory\Http\Resources\ValuationItemResource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Valuation', description: 'FIFO, Moving Average, Standard Costing, and balance sheet valuations')]
final class InventoryValuationConfigController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/valuation/config',
        summary: 'Get effective valuation method per item',
        description: 'Effective valuation method per item: item override, else category default, else weighted average.',
        tags: ['Inventory Valuation'],
        parameters: [
            new OA\Parameter(name: 'item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valuation configuration', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryValuationConfigRow')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get effective valuation method per item', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('inventory.valuation.view');

        $request->validate([
            'item_id' => ['sometimes', 'uuid'],
            'category_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = StockItem::query()->with('category')->orderBy('sku');
        if ($request->filled('item_id')) {
            $query->whereKey($request->query('item_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        $items = $query->paginate((int) $request->query('per_page', 50));

        return response()->json(ValuationConfigResource::collection($items)->response()->getData(true));
    }

    #[OA\Put(
        path: '/api/v1/inventory/valuation/categories/{id}',
        summary: 'Update category valuation method and GL accounts',
        tags: ['Inventory Valuation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['valuation_method'],
            properties: [
                new OA\Property(property: 'valuation_method', type: 'string', enum: ['fifo', 'weighted_average', 'standard_cost']),
                new OA\Property(property: 'inventory_account_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'cogs_account_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'ppv_account_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'landed_cost_clearing_account_id', type: 'string', format: 'uuid', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Category updated', content: new OA\JsonContent(ref: '#/components/schemas/InventoryValuationCategory')),
            new OA\Response(response: 403, description: 'Missing inventory.valuation.manage'),
            new OA\Response(response: 404, description: 'Category not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update category valuation method and GL accounts', tags: ['Inventory'])]
    public function updateCategory(string $id, UpdateCategoryValuationRequest $request, ChangeValuationMethodAction $action): JsonResponse
    {
        $category = StockItemCategory::query()->findOrFail($id);
        $this->authorize('inventory.valuation.manage');

        $updated = $action->updateCategory((string) $category->id, $request->validated());

        return response()->json(new ValuationCategoryResource($updated));
    }

    #[OA\Put(
        path: '/api/v1/inventory/valuation/items/{id}',
        summary: 'Update item valuation method, standard cost, weight and volume',
        description: 'Changing the effective method (or the standard cost of a standard-cost item) with stock on hand creates an inventory revaluation and its GL adjustment.',
        tags: ['Inventory Valuation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'valuation_method', type: 'string', enum: ['fifo', 'weighted_average', 'standard_cost'], nullable: true),
            new OA\Property(property: 'standard_cost', type: 'number'),
            new OA\Property(property: 'weight_kg', type: 'number', nullable: true),
            new OA\Property(property: 'volume_m3', type: 'number', nullable: true),
            new OA\Property(property: 'revalue_on_change', type: 'boolean', default: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Item updated', content: new OA\JsonContent(ref: '#/components/schemas/InventoryValuationItem')),
            new OA\Response(response: 403, description: 'Missing inventory.valuation.manage'),
            new OA\Response(response: 404, description: 'Item not found'),
            new OA\Response(response: 422, description: 'Validation failed or fiscal period closed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update item valuation settings', tags: ['Inventory'])]
    public function updateItem(string $id, UpdateItemValuationRequest $request, ChangeValuationMethodAction $action): JsonResponse
    {
        $item = StockItem::query()->findOrFail($id);
        $this->authorize('inventory.valuation.manage');

        $result = $action->updateItem($request->toDTO((string) $item->id));

        return response()->json((new ValuationItemResource($result['item']))->withRevaluation($result['revaluation']));
    }
}
