<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Contracts\ProjectExpenseServiceInterface;
use Modules\Projects\Http\Requests\StoreProjectExpenseRequest;
use Modules\Projects\Http\Resources\ProjectCostEntryResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use OpenApi\Attributes as OA;

final class ProjectExpenseController extends Controller
{
    public function __construct(
        private readonly ProjectExpenseServiceInterface $expenseService
    ) {}

    /**
     * Creates a new project in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/expenses',
        operationId: 'createProjectsExpense',
        description: 'Creates a new project in the active tenant.',
        summary: 'Create project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreProjectExpenseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectCostEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(string $projectId, StoreProjectExpenseRequest $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $payload = array_merge($request->validated(), [
            'project_id' => $project->id,
            'userId' => (string) auth()->id(),
        ]);

        $costEntry = $this->expenseService->recordExpense($payload);

        return response()->json([
            'message' => 'Project expense recorded successfully',
            'data' => new ProjectCostEntryResource($costEntry),
        ], 201);
    }

    /**
     * Approves a project expense and posts it to the general ledger queue.
     */
    #[OA\Post(
        path: '/api/v1/projects/expenses/{expenseId}/approve',
        operationId: 'approveProjectsExpense',
        description: 'Approves a project expense and posts it to the general ledger queue.',
        summary: 'Approve project expense',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'expenseId', in: 'path', required: true, description: 'Identifier of the expense', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectCostEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(string $expenseId): JsonResponse
    {
        $costEntry = ProjectCostEntry::findOrFail($expenseId);

        $approved = $this->expenseService->approveExpense($costEntry, (string) auth()->id());

        return response()->json([
            'message' => 'Project expense approved and posted to general ledger queue',
            'data' => new ProjectCostEntryResource($approved),
        ]);
    }
}
