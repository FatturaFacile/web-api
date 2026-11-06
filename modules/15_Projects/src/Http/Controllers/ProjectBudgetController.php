<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\Http\Requests\StoreProjectBudgetRequest;
use Modules\Projects\Http\Resources\ProjectBudgetResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use OpenApi\Attributes as OA;

final class ProjectBudgetController extends Controller
{
    public function __construct(
        private readonly ProjectBudgetServiceInterface $budgetService
    ) {}

    /**
     * Returns a single project of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/budget',
        operationId: 'getProjectsBudget',
        description: 'Returns a single project of the active tenant.',
        summary: 'Get project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectBudgetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $projectId): JsonResponse
    {
        $budget = ProjectBudget::with(['lines', 'versions'])
            ->where('project_id', $projectId)
            ->firstOrFail();

        return response()->json([
            'data' => new ProjectBudgetResource($budget),
        ]);
    }

    /**
     * Creates a new project in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/budget',
        operationId: 'createProjectsBudget',
        description: 'Creates a new project in the active tenant.',
        summary: 'Create project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreProjectBudgetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectBudgetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(string $projectId, StoreProjectBudgetRequest $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $dto = new ProjectBudgetDTO(
            projectId: $project->id,
            totalRevenueBudget: (float) $request->validated('total_revenue_budget'),
            totalCostBudget: (float) $request->validated('total_cost_budget'),
            labourBudget: (float) ($request->validated('labour_budget') ?? 0.0),
            materialBudget: (float) ($request->validated('material_budget') ?? 0.0),
            supplierBudget: (float) ($request->validated('supplier_budget') ?? 0.0),
            fleetBudget: (float) ($request->validated('fleet_budget') ?? 0.0),
            expenseBudget: (float) ($request->validated('expense_budget') ?? 0.0),
            contingencyBudget: (float) ($request->validated('contingency_budget') ?? 0.0),
            revisionReason: $request->validated('revision_reason'),
            lines: $request->validated('lines', []),
            userId: (string) auth()->id()
        );

        $budget = $this->budgetService->createOrUpdateBudget($dto);

        return response()->json([
            'message' => 'Project budget saved successfully',
            'data' => new ProjectBudgetResource($budget->load('lines')),
        ], 201);
    }

    /**
     * Establishes the baseline snapshot of the project budget.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/budget/baseline',
        operationId: 'baselineProjectsBudget',
        description: 'Establishes the baseline snapshot of the project budget.',
        summary: 'Baseline project budget',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'version_number', type: 'integer', nullable: true), new OA\Property(property: 'baseline_cost', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'), new OA\Property(property: 'baseline_revenue', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'), new OA\Property(property: 'created_at', type: 'string', format: 'date-time')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function baseline(string $projectId, Request $request): JsonResponse
    {
        $budget = ProjectBudget::where('project_id', $projectId)->firstOrFail();

        $budget = $this->budgetService->baselineBudget(
            budget: $budget,
            approvedBy: (string) auth()->id()
        );

        return response()->json([
            'message' => 'Budget baseline snapshot established successfully',
            'data' => [
                'version_number' => $budget->current_version_number,
                'baseline_cost' => $budget->total_cost_budget,
                'baseline_revenue' => $budget->total_revenue_budget,
                'created_at' => $budget->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Approves the budget of the project on behalf of the authenticated user.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/budget/approve',
        operationId: 'approveProjectsBudget',
        description: 'Approves the budget of the project on behalf of the authenticated user.',
        summary: 'Approve project budget',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectBudgetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(string $projectId): JsonResponse
    {
        $budget = ProjectBudget::where('project_id', $projectId)->firstOrFail();

        $budget = $this->budgetService->approveBudget(
            budget: $budget,
            approvedBy: (string) auth()->id()
        );

        return response()->json([
            'message' => 'Project budget approved successfully',
            'data' => new ProjectBudgetResource($budget),
        ]);
    }
}
