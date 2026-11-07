<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Actions\CloseProjectAction;
use Modules\Projects\Actions\CreateProjectAction;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\DTOs\CreateProjectDTO;
use Modules\Projects\Events\ProjectPausedEvent;
use Modules\Projects\Events\ProjectResumedEvent;
use Modules\Projects\Events\ProjectStartedEvent;
use Modules\Projects\Http\Requests\StoreProjectRequest;
use Modules\Projects\Http\Requests\UpdateProjectRequest;
use Modules\Projects\Http\Resources\ProjectFinancialSummaryResource;
use Modules\Projects\Http\Resources\ProjectResource;
use Modules\Projects\Models\Project;
use OpenApi\Attributes as OA;

final class ProjectController extends Controller
{
    /**
     * Returns the paginated list of projects of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects',
        operationId: 'listProjects',
        description: 'Returns the paginated list of projects of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List projects',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'project_type', in: 'query', required: false, description: 'Filter by project type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Filter by contact', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of projects', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsProjectResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $query = Project::query()
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('project_type'), fn ($q, $type) => $q->where('project_type', $type))
            ->when($request->query('contact_id'), fn ($q, $contactId) => $q->where('contact_id', $contactId))
            ->orderBy('created_at', 'desc');

        $projects = $query->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => ProjectResource::collection($projects),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    /**
     * Creates a new project in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/projects',
        operationId: 'createProject',
        description: 'Creates a new project in the active tenant.',
        summary: 'Create project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Id', in: 'header', required: false, description: 'Value of the X-Tenant-Id header', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreProjectRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreProjectRequest $request, CreateProjectAction $action, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $tenantId = (string) ($tenant ? $tenant->id : $request->header('X-Tenant-Id'));

        $dto = new CreateProjectDTO(
            contactId: $request->validated('contact_id'),
            projectNumber: $request->validated('project_number'),
            name: $request->validated('name'),
            projectType: $request->validated('project_type'),
            billingMethod: $request->validated('billing_method'),
            plannedStartDate: $request->validated('planned_start_date'),
            plannedEndDate: $request->validated('planned_end_date'),
            projectManagerUserId: $request->validated('project_manager_user_id'),
            contractValue: (float) $request->validated('contract_value'),
            portfolioId: $request->validated('portfolio_id'),
            description: $request->validated('description'),
            costCenterId: $request->validated('cost_center_id'),
            parentProjectId: $request->validated('parent_project_id'),
            phases: $request->validated('phases', []),
            milestones: $request->validated('milestones', []),
            userId: (string) auth()->id(),
            companyId: $tenantId
        );

        $project = $action->execute($dto, $tenantId);

        return response()->json([
            'message' => 'Project created successfully',
            'data' => new ProjectResource($project),
        ], 201);
    }

    /**
     * Returns a single project of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}',
        operationId: 'getProject',
        description: 'Returns a single project of the active tenant.',
        summary: 'Get project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $project = Project::with(['phases', 'milestones', 'financialSummary'])->findOrFail($id);

        return response()->json([
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Updates an existing project of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/projects/{id}',
        operationId: 'updateProject',
        description: 'Updates an existing project of the active tenant.',
        summary: 'Update project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsUpdateProjectRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(string $id, UpdateProjectRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->update($request->validated());

        return response()->json([
            'message' => 'Project updated successfully',
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Deletes a project of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/projects/{id}',
        operationId: 'deleteProject',
        description: 'Deletes a project of the active tenant.',
        summary: 'Delete project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully',
        ]);
    }

    /**
     * Sets the project to active, records the actual start date and publishes the project started event.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/start',
        operationId: 'startProject',
        description: 'Sets the project to active, records the actual start date and publishes the project started event.',
        summary: 'Start project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function start(string $id, EventDispatcher $dispatcher): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->update([
            'status' => 'active',
            'actual_start_date' => now(),
        ]);

        $dispatcher->dispatch(new ProjectStartedEvent(
            projectId: $project->id,
            projectNumber: $project->project_number,
            startDate: now()->toDateString(),
            tenantId: $project->tenant_id,
            companyId: $project->company_id
        ));

        return response()->json([
            'message' => 'Project started successfully',
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Puts the project on hold and publishes the project paused event with the given reason.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/pause',
        operationId: 'pauseProject',
        description: 'Puts the project on hold and publishes the project paused event with the given reason.',
        summary: 'Pause project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', default: 'Suspension of activities')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function pause(string $id, Request $request, EventDispatcher $dispatcher): JsonResponse
    {
        $project = Project::findOrFail($id);
        $reason = (string) $request->input('reason', 'Suspension of activities');
        $project->update(['status' => 'on_hold']);

        $dispatcher->publishAndDispatch(new ProjectPausedEvent(
            projectId: $project->id,
            projectNumber: $project->project_number,
            reason: $reason,
            pausedBy: (string) auth()->id(),
            tenantId: $project->tenant_id,
            companyId: $project->company_id
        ));

        return response()->json([
            'message' => 'Project placed on hold',
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Sets a paused project back to active and publishes the project resumed event.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/resume',
        operationId: 'resumeProject',
        description: 'Sets a paused project back to active and publishes the project resumed event.',
        summary: 'Resume project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function resume(string $id, EventDispatcher $dispatcher): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->update(['status' => 'active']);

        $dispatcher->publishAndDispatch(new ProjectResumedEvent(
            projectId: $project->id,
            projectNumber: $project->project_number,
            resumedBy: (string) auth()->id(),
            tenantId: $project->tenant_id,
            companyId: $project->company_id
        ));

        return response()->json([
            'message' => 'Project resumed to active execution',
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Closes the project through the closing action, recording the actor and the reason.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/close',
        operationId: 'closeProject',
        description: 'Closes the project through the closing action, recording the actor and the reason.',
        summary: 'Close project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', default: 'Administrative project closure')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function close(string $id, Request $request, CloseProjectAction $action): JsonResponse
    {
        $project = Project::findOrFail($id);
        $userId = (string) (auth()->id() ?? $project->project_manager_user_id);
        $reason = $request->input('reason', 'Administrative project closure');

        $closed = $action->execute($project, $userId, $reason);

        return response()->json([
            'message' => 'Project closed successfully',
            'data' => new ProjectResource($closed),
        ]);
    }

    /**
     * Returns the financial summary of the project, or null when none has been calculated yet.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/financial-summary',
        operationId: 'getFinancialSummaryProject',
        description: 'Returns the financial summary of the project, or null when none has been calculated yet.',
        summary: 'Get project financial summary',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectFinancialSummaryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function financialSummary(string $id): JsonResponse
    {
        $project = Project::with('financialSummary')->findOrFail($id);

        return response()->json([
            'data' => $project->financialSummary ? new ProjectFinancialSummaryResource($project->financialSummary) : null,
        ]);
    }

    /**
     * Recalculates the earned value management metrics of the project and returns the updated financial summary.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/recalculate-evm',
        operationId: 'recalculateEVMProject',
        description: 'Recalculates the earned value management metrics of the project and returns the updated financial summary.',
        summary: 'Recalculate project EVM',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectFinancialSummaryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function recalculateEVM(string $id, EarnedValueCalculationServiceInterface $evmService): JsonResponse
    {
        $project = Project::findOrFail($id);
        $summary = $evmService->recalculateEVM($project);

        return response()->json([
            'data' => new ProjectFinancialSummaryResource($summary),
        ]);
    }

    public function evm(string $id, EarnedValueCalculationServiceInterface $evmService): JsonResponse
    {
        return $this->recalculateEVM($id, $evmService);
    }

    /**
     * Returns the planned dates, phases, milestones and task dependencies needed to draw the Gantt chart of the project.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/gantt',
        operationId: 'getGanttProject',
        description: 'Returns the planned dates, phases, milestones and task dependencies needed to draw the Gantt chart of the project.',
        summary: 'Get project Gantt data',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'project_id', type: 'string', format: 'uuid'), new OA\Property(property: 'project_name', type: 'string'), new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'), new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'), new OA\Property(property: 'progress_percentage', type: 'number'), new OA\Property(property: 'phases'), new OA\Property(property: 'tasks')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function gantt(string $id): JsonResponse
    {
        $project = Project::with(['phases.milestones', 'phases.tasks.predecessors', 'tasks.successors'])->findOrFail($id);

        return response()->json([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'planned_start_date' => $project->planned_start_date?->toDateString(),
            'planned_end_date' => $project->planned_end_date?->toDateString(),
            'progress_percentage' => (float) $project->progress_percentage,
            'phases' => $project->phases,
            'tasks' => $project->tasks,
        ]);
    }
}
