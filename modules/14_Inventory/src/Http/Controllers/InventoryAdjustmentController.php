<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\CreateInventoryAdjustmentAction;
use Modules\Inventory\Actions\PostInventoryAdjustmentAction;
use Modules\Inventory\DTOs\InventoryAdjustmentDTO;
use Modules\Inventory\Http\Requests\StoreInventoryAdjustmentRequest;
use Modules\Inventory\Http\Requests\WorkflowActionRequest;
use Modules\Inventory\Http\Resources\InventoryAdjustmentResource;
use Modules\Inventory\Models\InventoryAdjustment;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Adjustments', description: 'Stock write-offs, discrepancies, damages, and approvals')]
final class InventoryAdjustmentController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/adjustments',
        summary: 'List adjustments',
        description: 'Retrieve inventory adjustments with approval status and variance details.',
        tags: ['Inventory Adjustments'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'pending_approval', 'approved', 'rejected'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated adjustments'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List adjustments', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryAdjustment::class);

        $query = InventoryAdjustment::with(['warehouse'])->orderBy('created_at', 'desc');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $adjustments = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(InventoryAdjustmentResource::collection($adjustments)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments',
        summary: 'Create inventory adjustment',
        description: 'Creates a stock adjustment. Variances > €500 automatically trigger Workflow Engine V2 approval.',
        tags: ['Inventory Adjustments'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'reason', 'lines'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'reason', type: 'string', example: 'damage_loss'),
                    new OA\Property(property: 'notes', type: 'string'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'quantity_delta', type: 'number', example: -2),
                            new OA\Property(property: 'unit_cost', type: 'number', example: 120.00),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Adjustment created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create inventory adjustment', tags: ['Inventory'])]
    public function store(StoreInventoryAdjustmentRequest $request, CreateInventoryAdjustmentAction $action): JsonResponse
    {
        $this->authorize('create', InventoryAdjustment::class);

        $dto = new InventoryAdjustmentDTO(
            warehouseId: $request->string('warehouse_id')->value(),
            reason: $request->string('reason')->value(),
            lines: (array) $request->input('lines'),
            userId: (string) auth()->id(),
            notes: $request->input('notes')
        );

        $adjustment = $action->execute($dto);

        return response()->json(new InventoryAdjustmentResource($adjustment), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/adjustments/{id}',
        summary: 'Get adjustment details',
        description: 'Retrieve adjustment status and valuation impact.',
        tags: ['Inventory Adjustments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Adjustment details'),
            new OA\Response(response: 404, description: 'Adjustment not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get adjustment details', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        $adjustment = InventoryAdjustment::with(['warehouse'])->findOrFail($id);
        $this->authorize('view', $adjustment);

        return response()->json(new InventoryAdjustmentResource($adjustment));
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/{id}/approve',
        summary: 'Approve inventory adjustment',
        description: 'Approves write-off adjustment and executes double-entry stock reductions. Enforces segregation of duties.',
        tags: ['Inventory Adjustments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Adjustment approved'),
            new OA\Response(response: 403, description: 'Segregation of duties violation'),
            new OA\Response(response: 404, description: 'Adjustment not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Approve inventory adjustment', tags: ['Inventory'])]
    public function approve(string $id, WorkflowActionRequest $request, PostInventoryAdjustmentAction $action): JsonResponse
    {
        $adjustment = InventoryAdjustment::findOrFail($id);
        $this->authorize('approve', $adjustment);

        // Lines can be passed in request or stored; execute posting
        $lines = (array) $request->input('lines', []);
        $updated = $action->execute($adjustment, $lines, (string) auth()->id());

        return response()->json(new InventoryAdjustmentResource($updated));
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/{id}/reject',
        summary: 'Reject inventory adjustment',
        description: 'Rejects write-off adjustment.',
        tags: ['Inventory Adjustments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Adjustment rejected'),
            new OA\Response(response: 404, description: 'Adjustment not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Reject inventory adjustment', tags: ['Inventory'])]
    public function reject(string $id, WorkflowActionRequest $request): JsonResponse
    {
        $adjustment = InventoryAdjustment::findOrFail($id);
        $this->authorize('reject', $adjustment);

        $adjustment->update([
            'status' => 'rejected',
            'is_locked' => false,
        ]);

        return response()->json(new InventoryAdjustmentResource($adjustment));
    }
}
