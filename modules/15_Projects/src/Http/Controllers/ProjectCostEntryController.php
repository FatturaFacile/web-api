<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Actions\RecordJobCostEntryAction;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Http\Requests\StoreProjectCostEntryRequest;
use Modules\Projects\Http\Resources\ProjectCostEntryResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use OpenApi\Attributes as OA;

final class ProjectCostEntryController extends Controller
{
    /**
     * Returns the paginated list of projects of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/costs',
        operationId: 'listProjectsCosts',
        description: 'Returns the paginated list of projects of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List projects',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cost_category', in: 'query', required: false, description: 'Filter by cost category', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'task_id', in: 'query', required: false, description: 'Filter by task', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of projects', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsProjectCostEntryResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(string $projectId, Request $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $query = ProjectCostEntry::where('project_id', $project->id)
            ->when($request->query('cost_category'), fn ($q, $cat) => $q->where('cost_category', $cat))
            ->when($request->query('task_id'), fn ($q, $taskId) => $q->where('task_id', $taskId))
            ->orderBy('incurred_date', 'desc');

        $entries = $query->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => ProjectCostEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * Creates a new project in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/costs',
        operationId: 'createProjectsCost',
        description: 'Creates a new project in the active tenant.',
        summary: 'Create project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreProjectCostEntryRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectCostEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(
        string $projectId,
        StoreProjectCostEntryRequest $request,
        RecordJobCostEntryAction $action,
        TenantContext $tenantContext
    ): JsonResponse {
        $project = Project::findOrFail($projectId);
        $tenant = $tenantContext->getTenant();
        $companyId = (string) ($tenant ? $tenant->id : $project->company_id);

        $dto = new RecordJobCostDTO(
            projectId: $project->id,
            costCategory: $request->validated('cost_category'),
            costSourceType: $request->validated('cost_source_type'),
            costSourceId: $request->validated('cost_source_id'),
            quantity: (float) $request->validated('quantity'),
            unitCost: (float) $request->validated('unit_cost'),
            totalCost: (float) $request->validated('total_cost'),
            incurredDate: $request->validated('incurred_date'),
            phaseId: $request->validated('phase_id'),
            taskId: $request->validated('task_id'),
            uom: $request->validated('uom', 'PCE'),
            isBillable: (bool) $request->validated('is_billable', true),
            allocations: $request->validated('allocations', []),
            userId: (string) auth()->id(),
            companyId: $companyId
        );

        $costEntry = $action->execute($dto);

        return response()->json([
            'message' => 'Cost entry accumulated successfully',
            'data' => new ProjectCostEntryResource($costEntry->load('allocations')),
        ], 201);
    }
}
