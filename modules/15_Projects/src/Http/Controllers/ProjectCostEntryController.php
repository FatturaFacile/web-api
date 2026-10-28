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

final class ProjectCostEntryController extends Controller
{
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
