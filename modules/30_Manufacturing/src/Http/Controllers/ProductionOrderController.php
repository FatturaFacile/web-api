<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\CreateProductionOrderRequest;
use Modules\Manufacturing\Http\Resources\MfgProductionOrderResource;
use Modules\Manufacturing\Models\MfgProductionOrder;
use OpenApi\Attributes as OA;

final class ProductionOrderController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private ProductionPlanningServiceInterface $planningService,
        private ProductionExecutionServiceInterface $executionService,
        private ProductionCostingServiceInterface $costingService
    ) {}

    /**
     * Returns the paginated list of production orders of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/production-orders',
        operationId: 'listManufacturingProductionOrders',
        description: 'Returns the paginated list of production orders of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List production orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of production orders', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $orders = MfgProductionOrder::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgProductionOrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Creates a new production order in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/production-orders',
        operationId: 'createManufacturingProductionOrder',
        description: 'Creates a new production order in the active tenant.',
        summary: 'Create production order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManufacturingCreateProductionOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Production order created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(CreateProductionOrderRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new CreateProductionOrderDTO(
            tenantId: $tenantId,
            mfgItemId: $validated['mfg_item_id'],
            targetQuantity: (float) $validated['target_quantity'],
            plannedStartDate: $validated['planned_start_date'],
            plannedEndDate: $validated['planned_end_date'],
            bomId: $validated['bom_id'] ?? null,
            routingId: $validated['routing_id'] ?? null,
            projectId: $validated['project_id'] ?? null,
            notes: $validated['notes'] ?? null
        );

        $order = $this->planningService->planOrder($dto);

        return response()->json([
            'message' => 'Production order planned successfully.',
            'data' => new MfgProductionOrderResource($order),
        ], 201);
    }

    /**
     * Returns a single production order of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/production-orders/{id}',
        operationId: 'getManufacturingProductionOrder',
        description: 'Returns a single production order of the active tenant.',
        summary: 'Get production order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the production order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $order = MfgProductionOrder::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json(['data' => new MfgProductionOrderResource($order)]);
    }

    /**
     * Releases a production order to the shop floor.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/production-orders/{id}/release',
        operationId: 'releaseManufacturingProductionOrder',
        description: 'Releases a production order to the shop floor.',
        summary: 'Release production order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the production order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function release(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->releaseOrder($id);

        return response()->json([
            'message' => 'Production order released.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    /**
     * Starts a released production order.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/production-orders/{id}/start',
        operationId: 'startManufacturingProductionOrder',
        description: 'Starts a released production order.',
        summary: 'Start production order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the production order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function start(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->startOrder($id);

        return response()->json([
            'message' => 'Production order started.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    /**
     * Completes a production order.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/production-orders/{id}/complete',
        operationId: 'completeManufacturingProductionOrder',
        description: 'Completes a production order.',
        summary: 'Complete production order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the production order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function complete(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->completeOrder($id);

        return response()->json([
            'message' => 'Production order completed.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    /**
     * Closes a completed production order.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/production-orders/{id}/close',
        operationId: 'closeManufacturingProductionOrder',
        description: 'Closes a completed production order.',
        summary: 'Close production order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the production order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function close(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->closeOrder($id);

        return response()->json([
            'message' => 'Production order closed.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    /**
     * Cancels a production order, recording the reason.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/production-orders/{id}/cancel',
        operationId: 'cancelManufacturingProductionOrder',
        description: 'Cancels a production order, recording the reason.',
        summary: 'Cancel production order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the production order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', default: 'Cancelled by user request')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgProductionOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cancel(Request $request, string $id): JsonResponse
    {
        $reason = (string) $request->input('reason', 'Cancelled by user request');
        $order = $this->executionService->cancelOrder($id, $reason);

        return response()->json([
            'message' => 'Production order cancelled.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    /**
     * Returns the standard cost rollup and the actual cost with its variance for a production order.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/production-orders/{id}/cost-rollup',
        operationId: 'getCostRollupManufacturingProductionOrder',
        description: 'Returns the standard cost rollup and the actual cost with its variance for a production order.',
        summary: 'Get production cost rollup',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Production Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the production order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'production_order_id'), new OA\Property(property: 'standard_cost_rollup', type: 'object', properties: [new OA\Property(property: 'material_cost', type: 'number'), new OA\Property(property: 'labor_cost', type: 'number'), new OA\Property(property: 'machine_cost', type: 'number'), new OA\Property(property: 'overhead_cost', type: 'number'), new OA\Property(property: 'total_standard_cost', type: 'number')]), new OA\Property(property: 'actual_cost_and_variance', type: 'object', properties: [new OA\Property(property: 'actual_material_cost', type: 'number'), new OA\Property(property: 'actual_labor_cost', type: 'number'), new OA\Property(property: 'actual_machine_cost', type: 'number'), new OA\Property(property: 'actual_overhead_cost', type: 'number'), new OA\Property(property: 'actual_total_cost', type: 'number'), new OA\Property(property: 'variance_total', type: 'number'), new OA\Property(property: 'variance_type', type: 'string')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function costRollup(Request $request, string $id): JsonResponse
    {
        $rollup = $this->costingService->calculateStandardCostRollup($id);
        $actuals = $this->costingService->calculateActualCostAndVariance($id);

        return response()->json([
            'production_order_id' => $id,
            'standard_cost_rollup' => $rollup,
            'actual_cost_and_variance' => $actuals,
        ]);
    }
}
