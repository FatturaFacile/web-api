<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Contracts\ProjectExpenseServiceInterface;
use Modules\Projects\Http\Requests\RejectionRequest;
use Modules\Projects\Http\Requests\StoreProjectExpenseRequest;
use Modules\Projects\Http\Resources\ProjectCostEntryResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use OpenApi\Attributes as OA;

/**
 * Out-of-pocket project expenses: recorded by any member with write access, approved or rejected by the managers.
 */
final class ProjectExpenseController extends Controller
{
    public function __construct(private readonly ProjectExpenseServiceInterface $expenseService) {}

    /**
     * Records an expense as pending approval.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/expenses', operationId: 'projectsExpensesCreate', summary: 'Record project expense', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreExpenseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Expense recorded (pending)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsCostEntry')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreProjectExpenseRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('contribute', $project);
        $entry = $this->expenseService->recordExpense([...$request->validated(), 'project_id' => $project->id, 'userId' => (string) $request->user()?->getKey()]);

        return response()->json(['message' => 'Expense recorded and waiting for approval', 'data' => new ProjectCostEntryResource($entry)], 201);
    }

    /**
     * Approves a pending expense: it enters the project cost and the ledger.
     */
    #[OA\Post(path: '/api/v1/projects/expenses/{expenseId}/approve', operationId: 'projectsExpensesApprove', summary: 'Approve project expense', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsExpenseId')],
        responses: [
            new OA\Response(response: 200, description: 'Expense approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsCostEntry')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function approve(string $expenseId, Request $request): JsonResponse
    {
        $entry = $this->expense($expenseId);

        return response()->json(['message' => 'Expense approved', 'data' => new ProjectCostEntryResource($this->expenseService->approveExpense($entry, (string) $request->user()?->getKey()))]);
    }

    /**
     * Rejects a pending expense.
     */
    #[OA\Post(path: '/api/v1/projects/expenses/{expenseId}/reject', operationId: 'projectsExpensesReject', summary: 'Reject project expense', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsExpenseId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsRejectionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Expense rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsCostEntry')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function reject(string $expenseId, RejectionRequest $request): JsonResponse
    {
        $entry = $this->expense($expenseId);

        return response()->json(['message' => 'Expense rejected', 'data' => new ProjectCostEntryResource($this->expenseService->rejectExpense($entry, (string) $request->user()?->getKey(), (string) $request->validated('reason')))]);
    }

    private function expense(string $expenseId): ProjectCostEntry
    {
        $entry = ProjectCostEntry::query()->whereKey($expenseId)->where('cost_source_type', 'project_expense')->firstOrFail();
        $this->authorize('manage', Project::findOrFail($entry->project_id));

        return $entry;
    }
}
