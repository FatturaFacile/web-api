<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\ExecuteDoubleEntryMovementAction;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Http\Requests\TransferStockRequest;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Models\StockMovement;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Stock Movements', description: 'Double-entry stock movements, internal transfers, and bin-to-bin relocation')]
final class StockMovementController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/movements',
        summary: 'List stock movements',
        description: 'Retrieve paginated append-only double-entry inventory ledger movements.',
        tags: ['Inventory Stock Movements'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'stock_item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'movement_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated stock movements'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List stock movements', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $query = StockMovement::with(['item', 'warehouse', 'sourceBin', 'destinationBin'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('stock_item_id')) {
            $query->where('stock_item_id', $request->query('stock_item_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->query('movement_type'));
        }

        $movements = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(StockMovementResource::collection($movements)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/movements/transfer',
        summary: 'Execute stock transfer',
        description: 'Executes atomic double-entry stock movement between bins or locations with pessimistic concurrency locking.',
        tags: ['Inventory Stock Movements'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock_item_id', 'warehouse_id', 'quantity', 'movement_type', 'unit_cost'],
                properties: [
                    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'quantity', type: 'number', example: 10),
                    new OA\Property(property: 'movement_type', type: 'string', example: 'INTERNAL_TRANSFER'),
                    new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'unit_cost', type: 'number', example: 45.00),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock movement executed'),
            new OA\Response(response: 422, description: 'Validation failed or insufficient inventory'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Execute stock transfer', tags: ['Inventory'])]
    public function transfer(TransferStockRequest $request, ExecuteDoubleEntryMovementAction $action): JsonResponse
    {
        $this->authorize('create', StockMovement::class);

        $dto = new StockTransferDTO(
            stockItemId: $request->string('stock_item_id')->value(),
            warehouseId: $request->string('warehouse_id')->value(),
            quantity: (float) $request->input('quantity'),
            movementType: $request->string('movement_type')->value(),
            sourceBinId: $request->input('source_bin_id'),
            destinationBinId: $request->input('destination_bin_id'),
            stockLotId: $request->input('stock_lot_id'),
            stockSerialNumberId: $request->input('stock_serial_number_id'),
            unitCost: (float) $request->input('unit_cost'),
            referenceType: $request->input('reference_type'),
            referenceId: $request->input('reference_id'),
            notes: $request->input('notes'),
            userId: (string) auth()->id()
        );

        $movement = $action->execute($dto);

        return response()->json(new StockMovementResource($movement->load(['item', 'warehouse'])), 201);
    }
}
