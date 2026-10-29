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

final class ProjectTimesheetController extends Controller
{
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
