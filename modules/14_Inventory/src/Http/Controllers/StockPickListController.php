<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\GeneratePickListAction;
use Modules\Inventory\Http\Resources\StockPickListResource;
use Modules\Inventory\Models\StockPickList;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Pick Lists', description: 'Warehouse picking lists, route sequencing, and order fulfillment')]
final class StockPickListController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/pick-lists',
        summary: 'List pick lists',
        description: 'Retrieve paginated picking lists with status and warehouse filtering.',
        tags: ['Inventory Pick Lists'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated pick lists'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List pick lists', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $query = StockPickList::with(['warehouse', 'lines.item', 'lines.bin'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $lists = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(StockPickListResource::collection($lists)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/pick-lists',
        summary: 'Generate pick list',
        description: 'Generates an optimized pick route list for warehouse fulfillment.',
        tags: ['Inventory Pick Lists'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'items'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'assigned_picker_user_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'origin_document_type', type: 'string', example: 'sales_order'),
                    new OA\Property(property: 'origin_document_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'quantity', type: 'number', example: 10),
                            new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Pick list generated'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Generate pick list', tags: ['Inventory'])]
    public function store(Request $request, GeneratePickListAction $action): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'assigned_picker_user_id' => ['nullable', 'uuid'],
            'origin_document_type' => ['nullable', 'string', 'max:255'],
            'origin_document_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.lot_id' => ['nullable', 'uuid'],
        ]);

        $pickList = $action->execute(
            warehouseId: (string) $validated['warehouse_id'],
            items: (array) $validated['items'],
            assignedPickerId: $validated['assigned_picker_user_id'] ?? null,
            originType: $validated['origin_document_type'] ?? null,
            originId: $validated['origin_document_id'] ?? null
        );

        return response()->json(new StockPickListResource($pickList), 201);
    }
}
