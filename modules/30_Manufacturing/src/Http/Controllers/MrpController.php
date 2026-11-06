<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\RunMrpRequest;
use Modules\Manufacturing\Http\Resources\MfgMrpRunResource;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;
use OpenApi\Attributes as OA;

final class MrpController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private MrpServiceInterface $mrpService
    ) {}

    /**
     * Returns the paginated list of runs of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/mrp/runs',
        operationId: 'listManufacturingMrpRuns',
        description: 'Returns the paginated list of runs of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List runs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing MRP'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of runs', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManufacturingMfgMrpRunResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $runs = MfgMrpRun::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgMrpRunResource::collection($runs),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    /**
     * Runs material requirements planning on the given horizon.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/mrp/runs',
        operationId: 'runManufacturingMrpRuns',
        description: 'Runs material requirements planning on the given horizon.',
        summary: 'Run MRP',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing MRP'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManufacturingRunMrpRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Run created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgMrpRunResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function run(RunMrpRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RunMrpDTO(
            tenantId: $tenantId,
            horizonStartDate: $validated['horizon_start_date'],
            horizonEndDate: $validated['horizon_end_date'],
            runType: $validated['run_type'] ?? 'regenerative',
            itemIds: $validated['item_ids'] ?? null,
            executedBy: $request->user()?->id
        );

        $run = $this->mrpService->executeMrpRun($dto);

        return response()->json([
            'message' => 'MRP run executed successfully.',
            'data' => new MfgMrpRunResource($run),
        ], 201);
    }

    /**
     * Returns the paginated planned orders of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/mrp/planned-orders',
        operationId: 'getPlannedOrdersManufacturingMrp',
        description: 'Returns the paginated planned orders of the active tenant, latest first.',
        summary: 'List MRP planned orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing MRP'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManufacturingMfgMrpPlannedOrderModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function plannedOrders(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $orders = MfgMrpPlannedOrder::where('tenant_id', $tenantId)->latest()->paginate(50);

        return response()->json(['data' => $orders]);
    }

    /**
     * Returns the paginated material shortages of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/mrp/shortages',
        operationId: 'getShortagesManufacturingMrp',
        description: 'Returns the paginated material shortages of the active tenant, latest first.',
        summary: 'List MRP shortages',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing MRP'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManufacturingMfgMrpShortageModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function shortages(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $shortages = MfgMrpShortage::where('tenant_id', $tenantId)->latest()->paginate(50);

        return response()->json(['data' => $shortages]);
    }

    /**
     * Converts a planned order into a firm order and returns its identifier.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/mrp/planned-orders/{id}/convert',
        operationId: 'convertPlannedOrderManufacturingMrpPlannedOrder',
        description: 'Converts a planned order into a firm order and returns its identifier.',
        summary: 'Convert planned order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing MRP'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the planned order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'converted_order_id', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function convertPlannedOrder(Request $request, string $id): JsonResponse
    {
        $convertedId = $this->mrpService->convertPlannedOrder($id);

        return response()->json([
            'message' => 'Planned order converted successfully.',
            'converted_order_id' => $convertedId,
        ]);
    }
}
