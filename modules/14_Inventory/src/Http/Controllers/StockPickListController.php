<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\GeneratePickListAction;
use Modules\Inventory\Http\Requests\ConfirmPickLineRequest;
use Modules\Inventory\Http\Requests\GeneratePickListRequest;
use Modules\Inventory\Http\Resources\StockPickListResource;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Services\PickConfirmationService;
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
            new OA\Response(response: 200, description: 'Paginated pick lists', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPickListPage')),
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
        description: 'Takes stock in FEFO or FIFO order, holds it, and orders the stops as a walk through the warehouse. What the warehouse cannot cover comes back in `shortages`. An item can instead follow an existing reservation with `reservation_id`.',
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
                            new OA\Property(property: 'reservation_id', type: 'string', format: 'uuid'),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Pick list generated', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPickList')),
            new OA\Response(response: 422, description: 'Validation failed, or nothing can be picked'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Generate pick list', tags: ['Inventory'])]
    public function store(GeneratePickListRequest $request, GeneratePickListAction $action): JsonResponse
    {
        $validated = $request->validated();

        $pickList = $action->execute(
            warehouseId: (string) $validated['warehouse_id'],
            items: (array) $validated['items'],
            assignedPickerId: $validated['assigned_picker_user_id'] ?? null,
            originType: $validated['origin_document_type'] ?? null,
            originId: $validated['origin_document_id'] ?? null
        );

        return response()->json(new StockPickListResource($pickList), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/pick-lists/{id}',
        summary: 'Get a pick list',
        description: 'The stops of the list in walking order.',
        tags: ['Inventory Pick Lists'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Pick list', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPickList')),
            new OA\Response(response: 404, description: 'Pick list not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get a pick list', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        return response()->json(new StockPickListResource(StockPickList::with(['warehouse', 'lines'])->findOrFail($id)));
    }

    #[OA\Get(
        path: '/api/v1/inventory/pick-lists/{id}/next-stop',
        summary: 'Next stop of a guided pick',
        description: 'The next pending stop with its bin and lot barcodes, for a picker guided step by step. `line` is null when the list is done.',
        tags: ['Inventory Pick Lists'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Next stop', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPickStop')),
            new OA\Response(response: 404, description: 'Pick list not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Next stop of a guided pick', tags: ['Inventory'])]
    public function nextStop(string $id): JsonResponse
    {
        $pickList = StockPickList::findOrFail($id);
        $pending = StockPickLine::query()->where('stock_pick_list_id', $pickList->id)->where('status', 'pending');
        $line = (clone $pending)->with(['bin', 'item', 'lot'])->orderBy('sequence_number')->first();

        return response()->json([
            'remaining_stops' => $pending->count(),
            'line' => $line === null ? null : [
                'id' => $line->id,
                'sequence_number' => $line->sequence_number,
                'bin_code' => $line->bin?->code,
                'bin_barcode' => $line->bin?->barcode,
                'stock_item_id' => $line->stock_item_id,
                'sku' => $line->item?->sku,
                'item_name' => $line->item?->name,
                'lot_number' => $line->lot?->lot_number,
                'expiration_date' => $line->lot?->expiration_date?->toDateString(),
                'quantity_to_pick' => (string) $line->quantity_to_pick,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/pick-lists/{id}/lines/{lineId}/confirm',
        summary: 'Confirm a pick line',
        description: 'Records what was picked. The stock moves to `destination_bin_id`, or leaves stock when none is given; a smaller quantity marks the line as shorted.',
        tags: ['Inventory Pick Lists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['quantity'],
                properties: [
                    new OA\Property(property: 'quantity', type: 'number', example: 4),
                    new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Line confirmed', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPickLine')),
            new OA\Response(response: 404, description: 'Line not found'),
            new OA\Response(response: 422, description: 'Validation failed, or the line is closed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Confirm a pick line', tags: ['Inventory'])]
    public function confirmLine(string $id, string $lineId, ConfirmPickLineRequest $request, PickConfirmationService $picking): JsonResponse
    {
        $line = StockPickLine::query()->where('stock_pick_list_id', $id)->findOrFail($lineId);
        $picked = $picking->confirm($line, (string) $request->input('quantity'), (string) auth()->id(), $request->input('destination_bin_id'));

        return response()->json($picked->fresh());
    }

    #[OA\Post(
        path: '/api/v1/inventory/pick-lists/{id}/cancel',
        summary: 'Cancel a pick list',
        description: 'Frees the stock of every stop not yet picked. Stops that follow a reservation go back to it.',
        tags: ['Inventory Pick Lists'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Pick list cancelled', content: new OA\JsonContent(ref: '#/components/schemas/InventoryPickList')),
            new OA\Response(response: 404, description: 'Pick list not found'),
            new OA\Response(response: 422, description: 'The list is already closed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Cancel a pick list', tags: ['Inventory'])]
    public function cancel(string $id, PickConfirmationService $picking): JsonResponse
    {
        return response()->json(new StockPickListResource($picking->cancel(StockPickList::findOrFail($id))));
    }
}
