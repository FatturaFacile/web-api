<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Projects\Actions\CreateProjectAction;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\DTOs\CreateProjectDTO;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\ReasonRequest;
use Modules\Projects\Http\Requests\StoreProjectRequest;
use Modules\Projects\Http\Requests\UpdateProjectRequest;
use Modules\Projects\Http\Resources\ProjectAuditEntryResource;
use Modules\Projects\Http\Resources\ProjectEvmSnapshotResource;
use Modules\Projects\Http\Resources\ProjectFinancialSummaryResource;
use Modules\Projects\Http\Resources\ProjectResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Projects\Models\ProjectEvmSnapshot;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Services\ProjectClosingService;
use Modules\Projects\Services\ProjectLifecycleService;
use Modules\Projects\Services\ProjectScheduleService;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Projects (commesse) of the active company: master data, lifecycle, closing, schedule views, EVM and audit trail.
 */
final class ProjectController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly ProjectClosingService $closing,
        private readonly ProjectScheduleService $schedule,
    ) {}

    /**
     * Lists the projects, filtered by status, type, customer or project manager.
     */
    #[OA\Get(path: '/api/v1/projects', operationId: 'projectsList', summary: 'List projects', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'project_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'project_manager_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Projects', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsProject')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
        ])]
    public function index(Request $request): JsonResponse
    {
        $projects = Project::query()
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('project_type'), fn ($query, $type) => $query->where('project_type', $type))
            ->when($request->query('contact_id'), fn ($query, $contactId) => $query->where('contact_id', $contactId))
            ->when($request->query('project_manager_user_id'), fn ($query, $userId) => $query->where('project_manager_user_id', $userId))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 15));

        return $this->paginated($projects, ProjectResource::class);
    }

    /**
     * Opens a project in draft (managers only).
     */
    #[OA\Post(path: '/api/v1/projects', operationId: 'projectsCreate', summary: 'Create project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreProjectRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(StoreProjectRequest $request, CreateProjectAction $action, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Project::class);
        $tenantId = (string) $tenantContext->getTenantId();
        $data = $request->validated();

        $project = $action->execute(new CreateProjectDTO(
            contactId: $data['contact_id'],
            projectNumber: $data['project_number'],
            name: $data['name'],
            projectType: $data['project_type'],
            billingMethod: $data['billing_method'],
            plannedStartDate: $data['planned_start_date'],
            plannedEndDate: $data['planned_end_date'],
            projectManagerUserId: $data['project_manager_user_id'],
            contractValue: Money::of($data['contract_value']),
            portfolioId: $data['portfolio_id'] ?? null,
            description: $data['description'] ?? null,
            costCenterId: $data['cost_center_id'] ?? null,
            parentProjectId: $data['parent_project_id'] ?? null,
            phases: $data['phases'] ?? [],
            milestones: $data['milestones'] ?? [],
            userId: (string) $request->user()?->getKey(),
            companyId: $tenantId,
            revenueRecognitionMethod: $data['revenue_recognition_method'] ?? null,
            currency: strtoupper($data['currency'] ?? 'EUR'),
        ), $tenantId);

        return response()->json(['message' => 'Project created', 'data' => new ProjectResource($project)], 201);
    }

    /**
     * One project with phases, milestones and financial summary.
     */
    #[OA\Get(path: '/api/v1/projects/{id}', operationId: 'projectsShow', summary: 'Get project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Project', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => new ProjectResource(Project::with(['phases', 'milestones', 'financialSummary'])->findOrFail($id))]);
    }

    /**
     * Changes the master data of an open project.
     */
    #[OA\Put(path: '/api/v1/projects/{id}', operationId: 'projectsUpdate', summary: 'Update project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsUpdateProjectRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Project updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function update(string $id, UpdateProjectRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $updated = $this->lifecycle->update($project, $request->validated(), (string) $request->user()?->getKey());

        return response()->json(['message' => 'Project updated', 'data' => new ProjectResource($updated)]);
    }

    /**
     * Deletes a draft project without bookings.
     */
    #[OA\Delete(path: '/api/v1/projects/{id}', operationId: 'projectsDelete', summary: 'Delete draft project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Project deleted', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function destroy(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('delete', $project);
        $this->lifecycle->delete($project);

        return response()->json(['message' => 'Project deleted']);
    }

    /**
     * Starts the project (draft, planned or approved to active).
     */
    #[OA\Post(path: '/api/v1/projects/{id}/start', operationId: 'projectsStart', summary: 'Start project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Project started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function start(string $id, Request $request): JsonResponse
    {
        return $this->transition($id, 'start', $request, null);
    }

    /**
     * Puts an active project on hold.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/pause', operationId: 'projectsPause', summary: 'Pause project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsReasonRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Project on hold', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function pause(string $id, ReasonRequest $request): JsonResponse
    {
        return $this->transition($id, 'pause', $request, $request->validated('reason'));
    }

    /**
     * Resumes a project on hold.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/resume', operationId: 'projectsResume', summary: 'Resume project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Project active again', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function resume(string $id, Request $request): JsonResponse
    {
        return $this->transition($id, 'resume', $request, null);
    }

    /**
     * Marks the work as completed; under the completed-contract method this is what lets revenue be recognised.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/complete', operationId: 'projectsComplete', summary: 'Complete project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Project completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function complete(string $id, Request $request): JsonResponse
    {
        return $this->transition($id, 'complete', $request, null);
    }

    /**
     * What still prevents the closure of the project.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/closure-checks', operationId: 'projectsClosureChecks', summary: 'Project closure checks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Blockers and warnings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsClosureChecks')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function closureChecks(string $id): JsonResponse
    {
        return response()->json(['data' => $this->closing->checks(Project::findOrFail($id))]);
    }

    /**
     * Closes the project after its checks, or sends it to the closure workflow when configured.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/close', operationId: 'projectsClose', summary: 'Close project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsReasonRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Project closed or pending closure approval', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, description: 'Closure blocked: the errors list the blockers', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ])]
    public function close(string $id, ReasonRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $closed = $this->closing->close($project, (string) $request->user()?->getKey(), $request->validated('reason'));

        return response()->json(['message' => $closed->status === 'closed' ? 'Project closed' : 'Closure sent for approval', 'data' => new ProjectResource($closed)]);
    }

    /**
     * Financial summary of the project, rebuilt on demand by the EVM recalculation.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/financial-summary', operationId: 'projectsFinancialSummary', summary: 'Project financial summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Summary (null before the first booking)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsFinancialSummary', nullable: true)])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function financialSummary(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $summary = ProjectFinancialSummary::query()->where('project_id', $project->id)->first();

        return response()->json(['data' => $summary !== null ? new ProjectFinancialSummaryResource($summary) : null]);
    }

    /**
     * Recomputes the earned value figures and stores the snapshot of the day.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/recalculate-evm', operationId: 'projectsRecalculateEvm', summary: 'Recalculate project EVM', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Updated summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsFinancialSummary')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function recalculateEVM(string $id, Request $request, EarnedValueCalculationServiceInterface $evm): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        return response()->json(['data' => new ProjectFinancialSummaryResource($evm->recalculateEVM($project, (string) $request->user()?->getKey()))]);
    }

    /**
     * History of the earned value snapshots, most recent first.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/evm-snapshots', operationId: 'projectsEvmSnapshots', summary: 'Project EVM snapshots', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Snapshots', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsEvmSnapshot')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function evmSnapshots(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);

        return $this->paginated(ProjectEvmSnapshot::query()->where('project_id', $project->id)->orderByDesc('snapshot_date')->paginate($this->perPage($request)), ProjectEvmSnapshotResource::class);
    }

    /**
     * Gantt data: tasks with planned, baseline and actual dates, dependencies and critical path.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/gantt', operationId: 'projectsGantt', summary: 'Project Gantt data', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Gantt', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsGantt')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function gantt(string $id): JsonResponse
    {
        return response()->json(['data' => $this->schedule->gantt(Project::findOrFail($id))]);
    }

    /**
     * Critical path computed on the planned dates and dependencies of the tasks.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/critical-path', operationId: 'projectsCriticalPath', summary: 'Project critical path', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Critical path', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsCriticalPath')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function criticalPath(string $id): JsonResponse
    {
        return response()->json(['data' => $this->schedule->criticalPath(Project::findOrFail($id))]);
    }

    /**
     * Freezes the current planned dates of the project and its tasks as the schedule baseline.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/schedule/baseline', operationId: 'projectsScheduleBaseline', summary: 'Baseline project schedule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Baseline frozen', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProject')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function baselineSchedule(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        return response()->json(['message' => 'Schedule baseline frozen', 'data' => new ProjectResource($this->schedule->baseline($project, (string) $request->user()?->getKey()))]);
    }

    /**
     * Business audit trail of the project, most recent first, optionally filtered by event type.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/audit-trail', operationId: 'projectsAuditTrail', summary: 'Project audit trail', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'event_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Audit entries', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsAuditEntry')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function auditTrail(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $request->validate(['event_type' => ['nullable', 'string', 'max:100', Rule::notIn([''])]]);

        $entries = ProjectAuditEntry::query()
            ->where('project_id', $project->id)
            ->when($request->query('event_type'), fn ($query, $type) => $query->where('event_type', $type))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($entries, ProjectAuditEntryResource::class);
    }

    /**
     * @param  'start'|'pause'|'resume'|'complete'  $transition
     */
    private function transition(string $id, string $transition, Request $request, ?string $reason): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $updated = $this->lifecycle->transition($project, $transition, (string) $request->user()?->getKey(), $reason);

        return response()->json(['message' => "Project {$transition} done", 'data' => new ProjectResource($updated)]);
    }
}
