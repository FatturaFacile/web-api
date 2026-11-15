<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\HumanResources\Http\Requests\SaveGoalCycleRequest;
use Modules\HumanResources\Http\Requests\SaveGoalKeyResultRequest;
use Modules\HumanResources\Http\Requests\SaveGoalRequest;
use Modules\HumanResources\Http\Requests\StoreGoalCheckInRequest;
use Modules\HumanResources\Http\Resources\HrRecordResource;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Goal;
use Modules\HumanResources\Models\GoalCycle;
use Modules\HumanResources\Models\GoalKeyResult;
use Modules\HumanResources\Models\Team;
use Modules\HumanResources\Services\Goals\GoalService;
use OpenApi\Attributes as OA;

/**
 * Goals and OKR: cycles, cascading goals, key results and check-ins.
 */
final class GoalsController extends Controller
{
    public function __construct(private readonly GoalService $goals) {}

    /**
     * List goal cycles.
     */
    #[OA\Get(
        path: '/api/v1/hr/goal-cycles',
        operationId: 'listGoalCycles',
        description: 'Return the goal cycles of the company, newest first.',
        summary: 'List goal cycles',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 200, description: 'Cycles', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function cycles(): AnonymousResourceCollection
    {
        return HrRecordResource::collection(GoalCycle::query()->orderByDesc('starts_on')->gridPaginate(RequestedPerPage::resolve(100)));
    }

    /**
     * Create goal cycle.
     */
    #[OA\Post(
        path: '/api/v1/hr/goal-cycles',
        operationId: 'storeGoalCycle',
        description: 'Create a goal cycle in draft or active.',
        summary: 'Create goal cycle',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveGoalCycleRequest')),
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 201, description: 'Cycle', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function storeCycle(SaveGoalCycleRequest $request): JsonResponse
    {
        return (new HrRecordResource($this->goals->createCycle($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Update goal cycle.
     */
    #[OA\Put(
        path: '/api/v1/hr/goal-cycles/{id}',
        operationId: 'updateGoalCycle',
        description: 'Change the name, the dates or the status (draft or active) of a cycle that is not closed.',
        summary: 'Update goal cycle',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveGoalCycleRequest')),
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Cycle', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function updateCycle(SaveGoalCycleRequest $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->goals->updateCycle(GoalCycle::query()->findOrFail($id), $request->validated()));
    }

    /**
     * Close goal cycle.
     */
    #[OA\Post(
        path: '/api/v1/hr/goal-cycles/{id}/close',
        operationId: 'closeGoalCycle',
        description: 'Close the cycle: its goals are frozen, finished goals are marked completed and drafts are cancelled.',
        summary: 'Close goal cycle',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Cycle', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function closeCycle(string $id): HrRecordResource
    {
        return new HrRecordResource($this->goals->closeCycle(GoalCycle::query()->findOrFail($id)));
    }

    /**
     * List teams.
     */
    #[OA\Get(
        path: '/api/v1/hr/teams',
        operationId: 'listHrTeams',
        description: 'Return the teams of the company, the owners of the team goals.',
        summary: 'List teams',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 200, description: 'Teams', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function teams(): AnonymousResourceCollection
    {
        return HrRecordResource::collection(Team::query()->orderBy('name')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * List goals.
     */
    #[OA\Get(
        path: '/api/v1/hr/goals',
        operationId: 'listGoals',
        description: 'Return the goals with their key results, filtered by cycle, level, employee, team, parent or status.',
        summary: 'List goals',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'goal_cycle_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'level', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['company', 'team', 'employee'])), new OA\Parameter(name: 'employee_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'team_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'parent_goal_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'active', 'completed', 'cancelled']))],
        responses: [
            new OA\Response(response: 200, description: 'Goals', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function goals(Request $request): AnonymousResourceCollection
    {
        return HrRecordResource::collection(Goal::query()
            ->with('keyResults')
            ->when($request->query('goal_cycle_id'), static fn ($query, $value) => $query->where('goal_cycle_id', $value))
            ->when($request->query('level'), static fn ($query, $value) => $query->where('level', $value))
            ->when($request->query('employee_id'), static fn ($query, $value) => $query->where('employee_id', $value))
            ->when($request->query('team_id'), static fn ($query, $value) => $query->where('team_id', $value))
            ->when($request->query('parent_goal_id'), static fn ($query, $value) => $query->where('parent_goal_id', $value))
            ->when($request->query('status'), static fn ($query, $value) => $query->where('status', $value))
            ->orderBy('level')
            ->orderBy('title')
            ->gridPaginate(RequestedPerPage::resolve(100)));
    }

    /**
     * Create goal.
     */
    #[OA\Post(
        path: '/api/v1/hr/goals',
        operationId: 'storeGoal',
        description: 'Create a goal of the company, of a team or of an employee, optionally cascading from a parent goal of a higher level and with its key results.',
        summary: 'Create goal',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveGoalRequest')),
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 201, description: 'Goal', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function storeGoal(SaveGoalRequest $request): JsonResponse
    {
        return (new HrRecordResource($this->goals->createGoal($request->validated())->load('keyResults')))->response()->setStatusCode(201);
    }

    /**
     * Show goal.
     */
    #[OA\Get(
        path: '/api/v1/hr/goals/{id}',
        operationId: 'showGoal',
        description: 'Return the goal with its key results, check-ins, parent and child goals.',
        summary: 'Show goal',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Goal', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ],
    )]
    public function showGoal(string $id): HrRecordResource
    {
        return new HrRecordResource(Goal::query()->with(['keyResults', 'checkIns', 'parent', 'children'])->findOrFail($id));
    }

    /**
     * Update goal.
     */
    #[OA\Put(
        path: '/api/v1/hr/goals/{id}',
        operationId: 'updateGoal',
        description: 'Change the title, weight, due date, status or parent of a goal of a cycle that is not closed.',
        summary: 'Update goal',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveGoalRequest')),
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Goal', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function updateGoal(SaveGoalRequest $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->goals->updateGoal(Goal::query()->findOrFail($id), $request->validated())->load('keyResults'));
    }

    /**
     * Delete goal.
     */
    #[OA\Delete(
        path: '/api/v1/hr/goals/{id}',
        operationId: 'deleteGoal',
        description: 'Delete a goal and its key results; its child goals become top-level goals.',
        summary: 'Delete goal',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function destroyGoal(string $id): Response
    {
        $this->goals->deleteGoal(Goal::query()->findOrFail($id));

        return response()->noContent();
    }

    /**
     * Add key result.
     */
    #[OA\Post(
        path: '/api/v1/hr/goals/{id}/key-results',
        operationId: 'storeGoalKeyResult',
        description: 'Add a measurable key result to a goal; the goal progress is recomputed.',
        summary: 'Add key result',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveGoalKeyResultRequest')),
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Key result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function storeKeyResult(SaveGoalKeyResultRequest $request, string $id): JsonResponse
    {
        return (new HrRecordResource($this->goals->addKeyResult(Goal::query()->findOrFail($id), $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Update key result.
     */
    #[OA\Put(
        path: '/api/v1/hr/goal-key-results/{id}',
        operationId: 'updateGoalKeyResult',
        description: 'Change a key result; the goal progress is recomputed.',
        summary: 'Update key result',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveGoalKeyResultRequest')),
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Key result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function updateKeyResult(SaveGoalKeyResultRequest $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->goals->updateKeyResult(GoalKeyResult::query()->findOrFail($id), $request->validated()));
    }

    /**
     * Delete key result.
     */
    #[OA\Delete(
        path: '/api/v1/hr/goal-key-results/{id}',
        operationId: 'deleteGoalKeyResult',
        description: 'Delete a key result; the goal progress is recomputed.',
        summary: 'Delete key result',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function destroyKeyResult(string $id): Response
    {
        $this->goals->deleteKeyResult(GoalKeyResult::query()->findOrFail($id));

        return response()->noContent();
    }

    /**
     * List goal check-ins.
     */
    #[OA\Get(
        path: '/api/v1/hr/goals/{id}/check-ins',
        operationId: 'listGoalCheckIns',
        description: 'Return the history of the check-ins of a goal, newest first.',
        summary: 'List goal check-ins',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Check-ins', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ],
    )]
    public function checkIns(string $id): AnonymousResourceCollection
    {
        return HrRecordResource::collection(Goal::query()->findOrFail($id)->checkIns()->gridPaginate(RequestedPerPage::resolve(100)));
    }

    /**
     * Check in on goal.
     */
    #[OA\Post(
        path: '/api/v1/hr/goals/{id}/check-ins',
        operationId: 'storeGoalCheckIn',
        description: 'Record the new value of a key result, or the progress of a goal that has no key results or child goals.',
        summary: 'Check in on goal',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesStoreGoalCheckInRequest')),
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Check-in', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(response: 409, description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function storeCheckIn(StoreGoalCheckInRequest $request, string $id): JsonResponse
    {
        return (new HrRecordResource($this->goals->checkIn(Goal::query()->findOrFail($id), $request->validated(), $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * Employee goal progress.
     */
    #[OA\Get(
        path: '/api/v1/hr/employees/{id}/goal-progress',
        operationId: 'getEmployeeGoalProgress',
        description: 'Return the weighted average progress (0-100) of the active and completed goals of an employee, optionally of one cycle: the figure a performance review can use as its goals score.',
        summary: 'Employee goal progress',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId'), new OA\Parameter(name: 'goal_cycle_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ],
    )]
    public function employeeProgress(Request $request, string $id): JsonResponse
    {
        $employee = Employee::query()->findOrFail($id);
        $cycleId = $request->query('goal_cycle_id');

        return response()->json(['data' => [
            'employee_id' => $employee->id,
            'goal_cycle_id' => $cycleId,
            'progress' => $this->goals->employeeProgress((string) $employee->id, is_string($cycleId) ? $cycleId : null),
        ]]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
