<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\Http\Requests\CreateInventoryCountSessionRequest;
use Modules\Inventory\Http\Requests\RecordCountRequest;
use Modules\Inventory\Http\Resources\InventoryAdjustmentResource;
use Modules\Inventory\Http\Resources\InventoryCountSessionResource;
use Modules\Inventory\Models\InventoryCountSession;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Count Sessions', description: 'Physical stocktaking, ABC cycle counts, blind counting, and reconciliation')]
final class InventoryCountController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/counts',
        summary: 'List inventory count sessions',
        description: 'Retrieve paginated physical stock counting sessions.',
        tags: ['Inventory Count Sessions'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'in_progress', 'completed', 'cancelled'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated count sessions'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List inventory count sessions', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryCountSession::class);

        $query = InventoryCountSession::with(['warehouse', 'zone'])->orderBy('created_at', 'desc');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $sessions = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(InventoryCountSessionResource::collection($sessions)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts',
        summary: 'Create count session',
        description: 'Generates a new cycle count or full physical count session with snapshot lines.',
        tags: ['Inventory Count Sessions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'count_type', 'scheduled_date'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'count_type', type: 'string', enum: ['full_physical', 'cycle_count_abc', 'spot_check', 'zone_sweep']),
                    new OA\Property(property: 'scheduled_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'warehouse_zone_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Session created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create count session', tags: ['Inventory'])]
    public function store(CreateInventoryCountSessionRequest $request, InventoryCountServiceInterface $service): JsonResponse
    {
        $this->authorize('create', InventoryCountSession::class);

        $dto = new InventoryCountSessionDTO(
            warehouseId: $request->string('warehouse_id')->value(),
            countType: $request->string('count_type')->value(),
            scheduledDate: $request->string('scheduled_date')->value(),
            zoneId: $request->input('warehouse_zone_id'),
            notes: $request->input('notes'),
            userId: (string) auth()->id()
        );

        $session = $service->createSession($dto);

        return response()->json(new InventoryCountSessionResource($session->load(['warehouse', 'lines'])), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/counts/{id}',
        summary: 'Get count session details',
        description: 'Retrieve count session header and counting lines.',
        tags: ['Inventory Count Sessions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Session details'),
            new OA\Response(response: 404, description: 'Session not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get count session details', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        $session = InventoryCountSession::with(['warehouse', 'zone', 'lines.item', 'lines.bin'])->findOrFail($id);
        $this->authorize('view', $session);

        return response()->json(new InventoryCountSessionResource($session));
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/record-count',
        summary: 'Record blind count on line',
        description: 'Records physically counted quantity on a session line.',
        tags: ['Inventory Count Sessions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['session_line_id', 'counted_quantity'],
                properties: [
                    new OA\Property(property: 'session_line_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'counted_quantity', type: 'number', example: 42),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Count recorded'),
            new OA\Response(response: 404, description: 'Session not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Record blind count on line', tags: ['Inventory'])]
    public function recordCount(string $id, RecordCountRequest $request, InventoryCountServiceInterface $service): JsonResponse
    {
        $session = InventoryCountSession::findOrFail($id);
        $this->authorize('recordCount', $session);

        $service->recordCount(
            sessionLineId: $request->string('session_line_id')->value(),
            countedQuantity: (float) $request->input('counted_quantity'),
            userId: (string) auth()->id()
        );

        return response()->json(['message' => 'Count recorded successfully']);
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/reconcile',
        summary: 'Reconcile count session',
        description: 'Reconciles counted quantities, posts adjustments or routes to Workflow Engine V2 if variance > €500.',
        tags: ['Inventory Count Sessions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Session reconciled and adjustment generated'),
            new OA\Response(response: 404, description: 'Session not found'),
            new OA\Response(response: 422, description: 'Session cannot be reconciled'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Reconcile count session', tags: ['Inventory'])]
    public function reconcile(string $id, InventoryCountServiceInterface $service): JsonResponse
    {
        $session = InventoryCountSession::findOrFail($id);
        $this->authorize('reconcile', $session);

        $adjustment = $service->reconcileSession($session, (string) auth()->id());

        return response()->json(new InventoryAdjustmentResource($adjustment));
    }
}
