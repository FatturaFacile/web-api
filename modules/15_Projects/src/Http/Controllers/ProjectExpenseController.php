<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Contracts\ProjectExpenseServiceInterface;
use Modules\Projects\Http\Requests\StoreProjectExpenseRequest;
use Modules\Projects\Http\Resources\ProjectCostEntryResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;

final class ProjectExpenseController extends Controller
{
    public function __construct(
        private readonly ProjectExpenseServiceInterface $expenseService
    ) {}

    public function store(string $projectId, StoreProjectExpenseRequest $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $payload = array_merge($request->validated(), [
            'project_id' => $project->id,
            'userId' => (string) auth()->id(),
        ]);

        $costEntry = $this->expenseService->recordExpense($payload);

        return response()->json([
            'message' => 'Project expense recorded successfully',
            'data' => new ProjectCostEntryResource($costEntry),
        ], 201);
    }

    public function approve(string $expenseId): JsonResponse
    {
        $costEntry = ProjectCostEntry::findOrFail($expenseId);

        $approved = $this->expenseService->approveExpense($costEntry, (string) auth()->id());

        return response()->json([
            'message' => 'Project expense approved and posted to general ledger queue',
            'data' => new ProjectCostEntryResource($approved),
        ]);
    }
}
