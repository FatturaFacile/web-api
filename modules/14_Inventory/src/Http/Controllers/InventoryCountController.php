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
use Modules\Inventory\Http\Requests\WmsQueryRequest;
use Modules\Inventory\Http\Resources\InventoryAdjustmentResource;
use Modules\Inventory\Http\Resources\InventoryCountSessionResource;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Services\InventoryCountService;
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
        description: 'Opens a physical count of a warehouse (optionally one zone or some bins) with one line per stock quant holding its system quantity (number from the gapless numbering, CNT-). With freezes_stock=true it is a physical inventory freeze: no movement may touch the counted bins (whole warehouse, or the zone) until the differences are approved or the session is cancelled; only the count adjustments themselves pass.',
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
                    new OA\Property(property: 'bin_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Count only these bins'),
                    new OA\Property(property: 'freezes_stock', type: 'boolean', example: true, description: 'Physical inventory freeze of the counted area'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'effective_at', type: 'string', format: 'date-time', description: 'Retroactive stocktake: compares the count with the stock at this past moment, and dates the adjustment there'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Session created', content: new OA\JsonContent(ref: '#/components/schemas/WmsCountSession')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
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
            userId: (string) auth()->id(),
            binIds: array_values((array) $request->input('bin_ids', [])),
            zoneId: $request->input('warehouse_zone_id'),
            notes: $request->input('notes'),
            effectiveAt: $request->input('effective_at'),
            freezesStock: $request->boolean('freezes_stock')
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
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Record blind count on line', tags: ['Inventory'])]
    public function recordCount(string $id, RecordCountRequest $request, InventoryCountServiceInterface $service): JsonResponse
    {
        $session = InventoryCountSession::findOrFail($id);
        $this->authorize('recordCount', $session);

        $service->recordCount(
            sessionLineId: $request->string('session_line_id')->value(),
            countedQuantity: (string) $request->input('counted_quantity'),
            userId: (string) auth()->id(),
            sessionId: (string) $session->id
        );

        return response()->json(['message' => 'Count recorded successfully']);
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/recount',
        operationId: 'wmsRecountCountLines',
        summary: 'Send count lines back for a recount',
        description: 'The given counted lines are counted again from scratch; the first count is kept in first_counted_quantity and recount_count goes up. Only for a session in progress.',
        tags: ['Inventory Count Sessions'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['line_ids'], properties: [
            new OA\Property(property: 'line_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Lines sent back', content: new OA\JsonContent(properties: [new OA\Property(property: 'recount_lines', type: 'integer', example: 2)])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function recount(string $id, WmsQueryRequest $request, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::findOrFail($id);
        $this->authorize('recount', $session);

        return response()->json(['recount_lines' => $service->requestRecount($session, array_values((array) $request->input('line_ids')))]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/cancel',
        operationId: 'wmsCancelCountSession',
        summary: 'Cancel a count session',
        description: 'Manager decision (owner/admin). Nothing is posted and the stock freeze of the session ends. Only a session in progress.',
        tags: ['Inventory Count Sessions'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Session cancelled', content: new OA\JsonContent(ref: '#/components/schemas/WmsCountSession')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function cancel(string $id, InventoryCountService $service): JsonResponse
    {
        $session = InventoryCountSession::findOrFail($id);
        $this->authorize('cancel', $session);

        return response()->json(new InventoryCountSessionResource($service->cancelSession($session)));
    }

    #[OA\Post(
        path: '/api/v1/inventory/counts/{id}/reconcile',
        summary: 'Approve the differences of a count session',
        description: 'Manager decision (owner/admin). Every counted line that differs from its system quantity becomes an adjustment line valued at the moving average cost (standard cost when none). Within the approval threshold (config inventory.adjustment_approval_threshold) the adjustment is posted at once: stock movements plus the cost impact to the General Ledger (inventory vs variance), and the session is reconciled. Above it, the INVENTORY_WRITE_OFF_APPROVAL workflow decides and the session stays pending_approval (and frozen) until then; a rejection puts it back in progress. Uncounted lines are not adjusted.',
        tags: ['Inventory Count Sessions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Adjustment generated (approved and posted, or pending approval)'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
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
