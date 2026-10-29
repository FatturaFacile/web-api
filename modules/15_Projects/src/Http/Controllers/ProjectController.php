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

final class ProjectController extends Controller
{
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

    public function store(StoreProjectRequest $request, CreateProjectAction $action, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $tenantId = (string) ($tenant ? $tenant->id : $request->header('X-Tenant-ID'));

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

    public function show(string $id): JsonResponse
    {
        $project = Project::with(['phases', 'milestones', 'financialSummary'])->findOrFail($id);

        return response()->json([
            'data' => new ProjectResource($project),
        ]);
    }

    public function update(string $id, UpdateProjectRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->update($request->validated());

        return response()->json([
            'message' => 'Project updated successfully',
            'data' => new ProjectResource($project),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully',
        ]);
    }

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

    public function pause(string $id, Request $request, EventDispatcher $dispatcher): JsonResponse
    {
        $project = Project::findOrFail($id);
        $reason = (string) $request->input('reason', 'Suspension of activities');
        $project->update(['status' => 'on_hold']);

        $dispatcher->publish(new ProjectPausedEvent(
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

    public function resume(string $id, EventDispatcher $dispatcher): JsonResponse
    {
        $project = Project::findOrFail($id);
        $project->update(['status' => 'active']);

        $dispatcher->publish(new ProjectResumedEvent(
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

    public function financialSummary(string $id): JsonResponse
    {
        $project = Project::with('financialSummary')->findOrFail($id);

        return response()->json([
            'data' => $project->financialSummary ? new ProjectFinancialSummaryResource($project->financialSummary) : null,
        ]);
    }

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
