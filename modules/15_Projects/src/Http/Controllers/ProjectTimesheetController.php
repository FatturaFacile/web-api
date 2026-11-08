<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\RejectionRequest;
use Modules\Projects\Http\Requests\StoreTimesheetRequest;
use Modules\Projects\Http\Resources\ProjectTimesheetResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Timesheets of a project and their approval flow (draft, submitted, approved or rejected; approved sheets are locked).
 */
final class ProjectTimesheetController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(private readonly ProjectTimesheetServiceInterface $timesheets) {}

    /**
     * Timesheets of the project, filtered by status, person or period.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/timesheets', operationId: 'projectsTimesheetsList', summary: 'List project timesheets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/ProjectsId'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'submitted', 'approved', 'rejected'])),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Timesheets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsTimesheet')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function index(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        $sheets = ProjectTimesheet::query()
            ->where('project_id', $project->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('work_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('work_date', '<=', $to))
            ->orderByDesc('work_date')
            ->paginate($this->perPage($request));

        return $this->paginated($sheets, ProjectTimesheetResource::class);
    }

    /**
     * Logs hours, for the caller or (managers only) for another member; draft unless submit is true.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/timesheets', operationId: 'projectsTimesheetsCreate', summary: 'Log project hours', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreTimesheetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Hours logged', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTimesheet')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreTimesheetRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('contribute', $project);
        $data = $request->validated();
        $actorId = (string) $request->user()?->getKey();
        $userId = $data['user_id'] ?? $actorId;

        if ($userId !== $actorId || isset($data['custom_cost_rate']) || isset($data['custom_charge_rate'])) {
            $this->authorize('manage', $project);
        }

        $timesheet = $this->timesheets->logTime(new TimesheetEntryDTO(
            projectId: $project->id,
            userId: $userId,
            workDate: $data['work_date'],
            hoursWorked: Money::of($data['hours_worked']),
            taskId: $data['task_id'] ?? null,
            overtimeHours: Money::of($data['overtime_hours'] ?? 0),
            isBillable: (bool) ($data['is_billable'] ?? true),
            customCostRate: isset($data['custom_cost_rate']) ? Money::of($data['custom_cost_rate']) : null,
            customChargeRate: isset($data['custom_charge_rate']) ? Money::of($data['custom_charge_rate']) : null,
            workSummary: $data['work_summary'] ?? null,
            role: $data['role'] ?? null,
            submit: (bool) ($data['submit'] ?? false),
        ));

        return response()->json(['message' => 'Hours logged', 'data' => new ProjectTimesheetResource($timesheet)], 201);
    }

    /**
     * Corrects a draft or rejected timesheet.
     */
    #[OA\Put(path: '/api/v1/projects/timesheets/{timesheetId}', operationId: 'projectsTimesheetsUpdate', summary: 'Update project timesheet', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTimesheetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreTimesheetRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Timesheet updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTimesheet')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function update(string $timesheetId, StoreTimesheetRequest $request): JsonResponse
    {
        $timesheet = $this->editable($timesheetId, 'update');
        $data = $request->validated();

        if (isset($data['custom_cost_rate']) || isset($data['custom_charge_rate'])) {
            $this->authorize('manage', $timesheet->project);
        }

        $changes = array_map(static fn (mixed $value): mixed => is_int($value) || is_float($value) ? Money::of($value) : $value, $data);

        return response()->json(['data' => new ProjectTimesheetResource($this->timesheets->updateTimesheet($timesheet, $changes))]);
    }

    /**
     * Deletes a draft or rejected timesheet.
     */
    #[OA\Delete(path: '/api/v1/projects/timesheets/{timesheetId}', operationId: 'projectsTimesheetsDelete', summary: 'Delete project timesheet', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTimesheetId')],
        responses: [
            new OA\Response(response: 200, description: 'Timesheet deleted', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function destroy(string $timesheetId): JsonResponse
    {
        $this->timesheets->deleteTimesheet($this->editable($timesheetId, 'delete'));

        return response()->json(['message' => 'Timesheet deleted']);
    }

    /**
     * Submits a draft or rejected timesheet for approval.
     */
    #[OA\Post(path: '/api/v1/projects/timesheets/{timesheetId}/submit', operationId: 'projectsTimesheetsSubmit', summary: 'Submit project timesheet', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTimesheetId')],
        responses: [
            new OA\Response(response: 200, description: 'Timesheet submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTimesheet')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function submit(string $timesheetId): JsonResponse
    {
        return response()->json(['data' => new ProjectTimesheetResource($this->timesheets->submitTimesheet($this->editable($timesheetId, 'submit')))]);
    }

    /**
     * Approves a submitted timesheet: it is locked and its labour cost is booked on the project.
     */
    #[OA\Post(path: '/api/v1/projects/timesheets/{timesheetId}/approve', operationId: 'projectsTimesheetsApprove', summary: 'Approve project timesheet', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTimesheetId')],
        responses: [
            new OA\Response(response: 200, description: 'Timesheet approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTimesheet')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function approve(string $timesheetId, Request $request): JsonResponse
    {
        $timesheet = ProjectTimesheet::with('project')->findOrFail($timesheetId);
        $this->authorize('approve', $timesheet);

        return response()->json(['message' => 'Timesheet approved and labour cost booked', 'data' => new ProjectTimesheetResource($this->timesheets->approveTimesheet($timesheet, (string) $request->user()?->getKey()))]);
    }

    /**
     * Sends a submitted timesheet back to its author with the reason.
     */
    #[OA\Post(path: '/api/v1/projects/timesheets/{timesheetId}/reject', operationId: 'projectsTimesheetsReject', summary: 'Reject project timesheet', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTimesheetId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsRejectionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Timesheet rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTimesheet')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function reject(string $timesheetId, RejectionRequest $request): JsonResponse
    {
        $timesheet = ProjectTimesheet::with('project')->findOrFail($timesheetId);
        $this->authorize('reject', $timesheet);

        return response()->json(['message' => 'Timesheet rejected', 'data' => new ProjectTimesheetResource($this->timesheets->rejectTimesheet($timesheet, (string) $request->user()?->getKey(), (string) $request->validated('reason')))]);
    }

    /**
     * A locked sheet answers 409 (state), a sheet of someone else 403 (permission).
     */
    private function editable(string $timesheetId, string $ability): ProjectTimesheet
    {
        $timesheet = ProjectTimesheet::with('project')->findOrFail($timesheetId);

        if ($timesheet->isEditable()) {
            $this->authorize($ability, $timesheet);
        } else {
            Gate::authorize('view', $timesheet);
        }

        return $timesheet;
    }
}
