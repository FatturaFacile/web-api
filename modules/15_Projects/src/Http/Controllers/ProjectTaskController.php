<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\StoreTaskAssignmentRequest;
use Modules\Projects\Http\Requests\StoreTaskDependencyRequest;
use Modules\Projects\Http\Requests\StoreTaskRequest;
use Modules\Projects\Http\Requests\UpdateTaskRequest;
use Modules\Projects\Http\Resources\ProjectTaskResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTaskAssignment;
use Modules\Projects\Models\ProjectTaskDependency;
use Modules\Projects\Services\ProjectScheduleService;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * WBS of a project: tasks, their dependencies and the people assigned to them.
 */
final class ProjectTaskController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(private readonly ProjectScheduleService $schedule) {}

    /**
     * Tasks of the project in WBS order, with assignments and predecessors.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/tasks', operationId: 'projectsTasksList', summary: 'List project tasks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Tasks', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsTask')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function index(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $tasks = ProjectTask::query()
            ->where('project_id', $project->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->with(['assignments', 'predecessors'])
            ->orderBy('sort_order')
            ->orderBy('planned_start_date')
            ->paginate($this->perPage($request, 50));

        return $this->paginated($tasks, ProjectTaskResource::class);
    }

    /**
     * Adds a task to the WBS.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/tasks', operationId: 'projectsTasksCreate', summary: 'Create project task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreTaskRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Task created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTask')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreTaskRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $task = $this->schedule->createTask($project, $request->validated());

        return response()->json(['data' => new ProjectTaskResource($task->load(['assignments', 'predecessors']))], 201);
    }

    /**
     * Updates a task (managers, or a person assigned to it).
     */
    #[OA\Put(path: '/api/v1/projects/tasks/{taskId}', operationId: 'projectsTasksUpdate', summary: 'Update project task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTaskId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsUpdateTaskRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Task updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTask')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function update(string $taskId, UpdateTaskRequest $request): JsonResponse
    {
        $task = ProjectTask::findOrFail($taskId);
        $this->authorize('update', $task);
        $updated = $this->schedule->updateTask($task, $request->validated());

        return response()->json(['data' => new ProjectTaskResource($updated->load(['assignments', 'predecessors']))]);
    }

    /**
     * Deletes a task without booked time or costs.
     */
    #[OA\Delete(path: '/api/v1/projects/tasks/{taskId}', operationId: 'projectsTasksDelete', summary: 'Delete project task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTaskId')],
        responses: [
            new OA\Response(response: 200, description: 'Task deleted', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function destroy(string $taskId): JsonResponse
    {
        $task = ProjectTask::findOrFail($taskId);
        $this->authorize('delete', $task);
        $this->schedule->deleteTask($task);

        return response()->json(['message' => 'Task deleted']);
    }

    /**
     * Adds a predecessor to the task; links that would close a cycle are refused.
     */
    #[OA\Post(path: '/api/v1/projects/tasks/{taskId}/dependencies', operationId: 'projectsTaskDependenciesCreate', summary: 'Add task dependency', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTaskId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreTaskDependencyRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Dependency added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTaskDependency')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function addDependency(string $taskId, StoreTaskDependencyRequest $request): JsonResponse
    {
        $task = ProjectTask::findOrFail($taskId);
        $this->authorize('plan', $task);
        $dependency = $this->schedule->addDependency($task, (string) $request->validated('predecessor_task_id'), (string) ($request->validated('dependency_type') ?? 'finish_to_start'), (int) ($request->validated('lag_days') ?? 0));

        return response()->json(['data' => ProjectTaskResource::dependency($dependency)], 201);
    }

    /**
     * Removes a dependency between two tasks.
     */
    #[OA\Delete(path: '/api/v1/projects/task-dependencies/{dependencyId}', operationId: 'projectsTaskDependenciesDelete', summary: 'Remove task dependency', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsDependencyId')],
        responses: [
            new OA\Response(response: 200, description: 'Dependency removed', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function removeDependency(string $dependencyId): JsonResponse
    {
        $dependency = ProjectTaskDependency::findOrFail($dependencyId);
        $this->authorize('plan', ProjectTask::findOrFail($dependency->successor_task_id));
        $this->schedule->removeDependency($dependency);

        return response()->json(['message' => 'Dependency removed']);
    }

    /**
     * Assigns a member of the company to the task with planned hours; assigning again updates the hours.
     */
    #[OA\Post(path: '/api/v1/projects/tasks/{taskId}/assignments', operationId: 'projectsTaskAssignmentsCreate', summary: 'Assign person to task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsTaskId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreTaskAssignmentRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Assignment saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsTaskAssignment')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function assign(string $taskId, StoreTaskAssignmentRequest $request): JsonResponse
    {
        $task = ProjectTask::findOrFail($taskId);
        $this->authorize('plan', $task);
        $assignment = $this->schedule->assign($task, (string) $request->validated('user_id'), Money::amount($request->validated('allocated_hours')), $request->validated('role_description'));

        return response()->json(['data' => ProjectTaskResource::assignment($assignment)], 201);
    }

    /**
     * Removes a person from a task.
     */
    #[OA\Delete(path: '/api/v1/projects/task-assignments/{assignmentId}', operationId: 'projectsTaskAssignmentsDelete', summary: 'Remove task assignment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsAssignmentId')],
        responses: [
            new OA\Response(response: 200, description: 'Assignment removed', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function unassign(string $assignmentId): JsonResponse
    {
        $assignment = ProjectTaskAssignment::findOrFail($assignmentId);
        $this->authorize('plan', ProjectTask::findOrFail($assignment->project_task_id));
        $assignment->delete();

        return response()->json(['message' => 'Assignment removed']);
    }
}
