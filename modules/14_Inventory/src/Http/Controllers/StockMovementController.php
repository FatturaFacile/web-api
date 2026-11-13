<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Actions\ExecuteDoubleEntryMovementAction;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Http\Requests\TransferStockRequest;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\WarehouseBin;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Stock Movements', description: 'Double-entry stock movements, internal transfers, and bin-to-bin relocation')]
final class StockMovementController extends Controller
{
    /**
     * @response AnonymousResourceCollection<LengthAwarePaginator<StockMovementResource>>
     */
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
            new OA\Response(response: 200, description: 'Paginated stock movements', content: new OA\JsonContent(ref: '#/components/schemas/InventoryStockMovementList')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List stock movements', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $query = StockMovement::with(['item', 'sourceBin', 'destinationBin'])
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

        $movements = $query->gridPaginate((int) $request->query('per_page', 25));

        return response()->json(StockMovementResource::collection($movements)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/movements/transfer',
        summary: 'Relocate stock between two bins of a warehouse',
        description: 'Moves stock from one bin to another bin of the same warehouse (internal relocation), as a double-entry movement under row locks, valued at the moving average cost so the stock value does not change. Stock cannot be created or destroyed here: receipts come from Procurement or the app purchase receipts, deliveries from delivery notes and the till, corrections from inventory adjustments (which also post to the General Ledger). One-sided movement types and `unit_cost` are no longer accepted/used (unit_cost is ignored). Transfers between warehouses use transfer orders. Refused (422) when a bin is frozen by a physical count or the source lacks available stock.',
        tags: ['Inventory Stock Movements'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock_item_id', 'warehouse_id', 'quantity', 'source_bin_id', 'destination_bin_id'],
                properties: [
                    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'Warehouse both bins belong to'),
                    new OA\Property(property: 'quantity', type: 'number', example: 10),
                    new OA\Property(property: 'movement_type', type: 'string', enum: ['INTERNAL_TRANSFER'], example: 'INTERNAL_TRANSFER'),
                    new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'stock_lot_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'stock_serial_number_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'unit_cost', type: 'number', nullable: true, deprecated: true, description: 'Ignored: relocations are valued at the moving average cost'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock relocated', content: new OA\JsonContent(ref: '#/components/schemas/InventoryStockMovement')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed, bins not in the warehouse, bin frozen or insufficient stock', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Relocate stock between two bins', tags: ['Inventory'])]
    public function transfer(TransferStockRequest $request, ExecuteDoubleEntryMovementAction $action): JsonResponse
    {
        $this->authorize('create', StockMovement::class);

        $warehouseId = $request->string('warehouse_id')->value();
        $binIds = [$request->string('source_bin_id')->value(), $request->string('destination_bin_id')->value()];

        if (WarehouseBin::query()->whereIn('id', $binIds)->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId))->count() !== 2) {
            throw new StockRuleViolation('Both bins must belong to the given warehouse: use a transfer order to move stock between warehouses.');
        }

        $dto = new StockTransferDTO(
            stockItemId: $request->string('stock_item_id')->value(),
            sourceBinId: $binIds[0],
            destinationBinId: $binIds[1],
            quantity: (float) $request->input('quantity'),
            movementType: 'INTERNAL_TRANSFER',
            lotId: $request->input('stock_lot_id'),
            serialNumberId: $request->input('stock_serial_number_id'),
            originDocumentType: $request->input('reference_type'),
            originDocumentId: $request->input('reference_id'),
            userId: (string) auth()->id(),
            notes: $request->input('notes')
        );

        $movement = $action->execute($dto);

        return response()->json(new StockMovementResource($movement->load(['item', 'sourceBin', 'destinationBin'])), 201);
    }
}
