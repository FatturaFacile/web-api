<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\AllocateStockReservationAction;
use Modules\Inventory\Actions\ReleaseStockReservationAction;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Http\Requests\AllocateStockReservationRequest;
use Modules\Inventory\Http\Resources\StockReservationResource;
use Modules\Inventory\Models\StockReservation;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Stock Reservations', description: 'Hard reservations, soft commitments, and reservation lifecycles')]
final class StockReservationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/reservations',
        summary: 'List stock reservations',
        description: 'Retrieve paginated active and completed inventory reservations.',
        tags: ['Inventory Stock Reservations'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'stock_item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'released', 'consumed', 'expired'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated reservations'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List stock reservations', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockReservation::class);

        $query = StockReservation::with(['item', 'warehouse', 'bin'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('stock_item_id')) {
            $query->where('stock_item_id', $request->query('stock_item_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $reservations = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(StockReservationResource::collection($reservations)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/reservations',
        summary: 'Allocate stock reservation',
        description: 'Reserves physical available stock for sales orders, service interventions, or projects.',
        tags: ['Inventory Stock Reservations'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock_item_id', 'warehouse_id', 'quantity', 'source_type', 'source_id'],
                properties: [
                    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'quantity', type: 'number', example: 5),
                    new OA\Property(property: 'source_type', type: 'string', example: 'sales_order'),
                    new OA\Property(property: 'source_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock reserved'),
            new OA\Response(response: 422, description: 'Insufficient stock or validation error'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Allocate stock reservation', tags: ['Inventory'])]
    public function store(AllocateStockReservationRequest $request, AllocateStockReservationAction $action): JsonResponse
    {
        $this->authorize('create', StockReservation::class);

        $dto = new StockReservationDTO(
            stockItemId: $request->string('stock_item_id')->value(),
            warehouseId: $request->string('warehouse_id')->value(),
            quantity: (float) $request->input('quantity'),
            sourceType: $request->string('source_type')->value(),
            sourceId: $request->string('source_id')->value(),
            warehouseBinId: $request->input('warehouse_bin_id'),
            userId: (string) auth()->id(),
            expiresAt: $request->filled('expires_at') ? now()->parse($request->input('expires_at')) : null
        );

        $reservation = $action->execute($dto);

        return response()->json(new StockReservationResource($reservation->load(['item', 'warehouse'])), 201);
    }

    #[OA\Post(
        path: '/api/v1/inventory/reservations/{id}/release',
        summary: 'Release stock reservation',
        description: 'Releases reserved stock back to available warehouse quants.',
        tags: ['Inventory Stock Reservations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reservation released'),
            new OA\Response(response: 404, description: 'Reservation not found'),
            new OA\Response(response: 422, description: 'Reservation cannot be released'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Release stock reservation', tags: ['Inventory'])]
    public function release(string $id, ReleaseStockReservationAction $action): JsonResponse
    {
        $reservation = StockReservation::findOrFail($id);
        $this->authorize('release', $reservation);

        $action->execute($reservation);

        return response()->json(['message' => 'Reservation released successfully']);
    }
}
