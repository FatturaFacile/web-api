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

final class ProjectBudgetController extends Controller
{
    public function __construct(
        private readonly ProjectBudgetServiceInterface $budgetService
    ) {}

    public function show(string $projectId): JsonResponse
    {
        $budget = ProjectBudget::with(['lines', 'versions'])
            ->where('project_id', $projectId)
            ->firstOrFail();

        return response()->json([
            'data' => new ProjectBudgetResource($budget),
        ]);
    }

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
