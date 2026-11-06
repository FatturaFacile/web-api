<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Actions\ExecuteChangeOrderAction;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\Http\Requests\StoreChangeOrderRequest;
use Modules\Projects\Http\Resources\ProjectChangeOrderResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectChangeOrder;
use OpenApi\Attributes as OA;

final class ProjectChangeOrderController extends Controller
{
    public function __construct(
        private readonly ProjectChangeOrderServiceInterface $changeOrderService
    ) {}

    /**
     * Returns the paginated list of projects of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/change-orders',
        operationId: 'listProjectsChangeOrders',
        description: 'Returns the paginated list of projects of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List projects',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of projects', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsProjectChangeOrderResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(string $projectId, Request $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $query = ProjectChangeOrder::where('project_id', $project->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', 'desc');

        $orders = $query->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => ProjectChangeOrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Creates a new project in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/change-orders',
        operationId: 'createProjectsChangeOrder',
        description: 'Creates a new project in the active tenant.',
        summary: 'Create project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreChangeOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectChangeOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(string $projectId, StoreChangeOrderRequest $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $dto = new ChangeOrderDTO(
            projectId: $project->id,
            orderNumber: $request->validated('order_number'),
            title: $request->validated('title'),
            scopeDescription: $request->validated('scope_description'),
            costImpactAmount: (float) $request->validated('cost_impact_amount'),
            revenueImpactAmount: (float) ($request->validated('revenue_impact_amount') ?? 0.0),
            scheduleImpactDays: (int) ($request->validated('schedule_impact_days') ?? 0),
            lines: $request->validated('lines', []),
            userId: (string) auth()->id()
        );

        $changeOrder = $this->changeOrderService->createChangeOrder($dto);

        return response()->json([
            'message' => 'Project change order created successfully',
            'data' => new ProjectChangeOrderResource($changeOrder->load('lines')),
        ], 201);
    }

    /**
     * Approves a change order and updates the project baseline.
     */
    #[OA\Post(
        path: '/api/v1/projects/change-orders/{orderId}/approve',
        operationId: 'approveProjectsChangeOrder',
        description: 'Approves a change order and updates the project baseline.',
        summary: 'Approve project change order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Identifier of the order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectChangeOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(string $id, ExecuteChangeOrderAction $action): JsonResponse
    {
        $changeOrder = ProjectChangeOrder::findOrFail($id);

        $approvedOrder = $action->execute($changeOrder, (string) auth()->id());

        return response()->json([
            'message' => 'Project change order approved and baseline updated',
            'data' => new ProjectChangeOrderResource($approvedOrder),
        ]);
    }
}
