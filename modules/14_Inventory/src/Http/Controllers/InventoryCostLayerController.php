<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\InventoryCostLayerResource;
use Modules\Inventory\Models\InventoryCostLayer;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Valuation', description: 'FIFO, Moving Average, Standard Costing, and balance sheet valuations')]
final class InventoryCostLayerController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/valuation/cost-layers',
        summary: 'List inventory cost layers',
        description: 'FIFO cost layers with remaining quantity and landed cost uplift (effective unit cost = unit cost + landed cost per unit).',
        tags: ['Inventory Valuation'],
        parameters: [
            new OA\Parameter(name: 'item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lot_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'open_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated cost layers', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryCostLayer')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List inventory cost layers', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('inventory.valuation.view');

        $request->validate([
            'item_id' => ['sometimes', 'uuid'],
            'warehouse_id' => ['sometimes', 'uuid'],
            'lot_id' => ['sometimes', 'uuid'],
            'open_only' => ['sometimes', 'in:0,1,true,false'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = InventoryCostLayer::query()->orderBy('received_at')->orderBy('layer_sequence');

        foreach (['item_id' => 'stock_item_id', 'warehouse_id' => 'warehouse_id', 'lot_id' => 'lot_id'] as $param => $column) {
            if ($request->filled($param)) {
                $query->where($column, $request->query($param));
            }
        }

        if ($request->boolean('open_only')) {
            $query->where('remaining_quantity', '>', 0);
        }

        $layers = $query->paginate((int) $request->query('per_page', 50));

        return response()->json(InventoryCostLayerResource::collection($layers)->response()->getData(true));
    }
}
