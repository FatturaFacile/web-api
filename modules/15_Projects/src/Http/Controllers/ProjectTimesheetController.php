<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Actions\ApproveProjectTimesheetAction;
use Modules\Projects\Actions\SubmitProjectTimesheetAction;
use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Http\Requests\StoreTimesheetRequest;
use Modules\Projects\Http\Resources\ProjectTimesheetResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTimesheet;
use OpenApi\Attributes as OA;

final class ProjectTimesheetController extends Controller
{
    /**
     * Returns the paginated list of projects of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/timesheets',
        operationId: 'listProjectsTimesheets',
        description: 'Returns the paginated list of projects of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List projects',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, description: 'Filter by user', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of projects', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsProjectTimesheetResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(string $projectId, Request $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $query = ProjectTimesheet::where('project_id', $project->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->orderBy('work_date', 'desc');

        $timesheets = $query->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => ProjectTimesheetResource::collection($timesheets),
            'meta' => [
                'current_page' => $timesheets->currentPage(),
                'last_page' => $timesheets->lastPage(),
                'total' => $timesheets->total(),
            ],
        ]);
    }

    /**
     * Creates a new project in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/timesheets',
        operationId: 'createProjectsTimesheet',
        description: 'Creates a new project in the active tenant.',
        summary: 'Create project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreTimesheetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectTimesheetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(
        string $projectId,
        StoreTimesheetRequest $request,
        SubmitProjectTimesheetAction $action
    ): JsonResponse {
        $project = Project::findOrFail($projectId);

        $dto = new TimesheetEntryDTO(
            projectId: $project->id,
            userId: $request->validated('user_id'),
            workDate: $request->validated('work_date'),
            hoursWorked: (float) $request->validated('hours_worked'),
            taskId: $request->validated('task_id'),
            overtimeHours: (float) ($request->validated('overtime_hours') ?? 0.0),
            isBillable: (bool) ($request->validated('is_billable') ?? true),
            customCostRate: $request->validated('custom_cost_rate') ? (float) $request->validated('custom_cost_rate') : null,
            customChargeRate: $request->validated('custom_charge_rate') ? (float) $request->validated('custom_charge_rate') : null,
            workSummary: $request->validated('work_summary'),
            approverUserId: null
        );

        $timesheet = $action->execute($dto);

        return response()->json([
            'message' => 'Project timesheet logged successfully',
            'data' => new ProjectTimesheetResource($timesheet),
        ], 201);
    }

    /**
     * Approves a project timesheet and posts the labour cost.
     */
    #[OA\Post(
        path: '/api/v1/projects/timesheets/{timesheetId}/approve',
        operationId: 'approveProjectsTimesheet',
        description: 'Approves a project timesheet and posts the labour cost.',
        summary: 'Approve project timesheet',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'timesheetId', in: 'path', required: true, description: 'Identifier of the timesheet', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProjectTimesheetResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(string $id, ApproveProjectTimesheetAction $action): JsonResponse
    {
        $timesheet = ProjectTimesheet::findOrFail($id);

        $approvedTimesheet = $action->execute($timesheet, (string) auth()->id());

        return response()->json([
            'message' => 'Project timesheet approved and labour cost posted',
            'data' => new ProjectTimesheetResource($approvedTimesheet),
        ]);
    }
}
