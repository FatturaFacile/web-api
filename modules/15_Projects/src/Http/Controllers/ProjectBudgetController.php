<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\Http\Requests\RejectionRequest;
use Modules\Projects\Http\Requests\StoreProjectBudgetRequest;
use Modules\Projects\Http\Resources\ProjectBudgetResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Services\ProjectBudgetService;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Budget of a project by cost category: revisions, approval, baselines and the budget against commitments and actuals.
 */
final class ProjectBudgetController extends Controller
{
    public function __construct(private readonly ProjectBudgetService $budgetService) {}

    /**
     * The budget with its lines, versions and the report of budget, commitments, actuals and availability by category.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/budget', operationId: 'projectsBudgetShow', summary: 'Get project budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Budget', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsBudget')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function show(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $budget = ProjectBudget::with(['lines', 'versions'])->where('project_id', $project->id)->firstOrFail();

        return response()->json(['data' => [...(new ProjectBudgetResource($budget))->resolve(), 'category_report' => $this->budgetService->categoryReport($project)]]);
    }

    /**
     * Saves a budget revision as draft (or sends it to the approval workflow when configured).
     */
    #[OA\Post(path: '/api/v1/projects/{id}/budget', operationId: 'projectsBudgetStore', summary: 'Save project budget revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreBudgetRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Budget saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsBudget')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreProjectBudgetRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $data = $request->validated();

        $budget = $this->budgetService->createOrUpdateBudget(new ProjectBudgetDTO(
            projectId: $project->id,
            totalRevenueBudget: Money::of($data['total_revenue_budget']),
            labourBudget: Money::of($data['labour_budget'] ?? 0),
            materialBudget: Money::of($data['material_budget'] ?? 0),
            supplierBudget: Money::of($data['supplier_budget'] ?? 0),
            fleetBudget: Money::of($data['fleet_budget'] ?? 0),
            expenseBudget: Money::of($data['expense_budget'] ?? 0),
            contingencyBudget: Money::of($data['contingency_budget'] ?? 0),
            lines: $data['lines'] ?? [],
            revisionReason: $data['revision_reason'] ?? null,
            userId: (string) $request->user()?->getKey(),
        ));

        return response()->json(['message' => 'Budget saved', 'data' => new ProjectBudgetResource($budget->load('lines'))], 201);
    }

    /**
     * Freezes the approved budget as a new baseline.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/budget/baseline', operationId: 'projectsBudgetBaseline', summary: 'Re-baseline project budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Baseline frozen', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsBudget')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function baseline(string $id, Request $request): JsonResponse
    {
        $budget = $this->budgetOf($id);
        $this->authorize('approve', $budget);

        return response()->json(['message' => 'Budget baseline frozen', 'data' => new ProjectBudgetResource($this->budgetService->baselineBudget($budget, (string) $request->user()?->getKey())->load('versions'))]);
    }

    /**
     * Approves the draft or pending budget and freezes its baseline.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/budget/approve', operationId: 'projectsBudgetApprove', summary: 'Approve project budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Budget approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsBudget')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function approve(string $id, Request $request): JsonResponse
    {
        $budget = $this->budgetOf($id);
        $this->authorize('approve', $budget);

        return response()->json(['message' => 'Budget approved', 'data' => new ProjectBudgetResource($this->budgetService->approveBudget($budget, (string) $request->user()?->getKey()))]);
    }

    /**
     * Rejects the draft or pending budget.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/budget/reject', operationId: 'projectsBudgetReject', summary: 'Reject project budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsRejectionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Budget rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsBudget')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function reject(string $id, RejectionRequest $request): JsonResponse
    {
        $budget = $this->budgetOf($id);
        $this->authorize('approve', $budget);

        return response()->json(['message' => 'Budget rejected', 'data' => new ProjectBudgetResource($this->budgetService->rejectBudget($budget, (string) $request->user()?->getKey(), (string) $request->validated('reason')))]);
    }

    private function budgetOf(string $projectId): ProjectBudget
    {
        $project = Project::findOrFail($projectId);

        return ProjectBudget::query()->where('project_id', $project->id)->firstOrFail();
    }
}
