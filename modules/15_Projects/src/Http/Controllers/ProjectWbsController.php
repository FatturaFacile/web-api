<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Http\Requests\ReorderWbsNodesRequest;
use Modules\Projects\Http\Requests\StoreWbsNodeRequest;
use Modules\Projects\Http\Requests\UpdateWbsNodeRequest;
use Modules\Projects\Http\Resources\ProjectWbsNodeResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Queries\GetProjectWbsKanbanQuery;
use Modules\Projects\Services\ProjectWbsScheduleService;
use Modules\Projects\Services\ProjectWbsService;
use OpenApi\Attributes as OA;

/**
 * Multilevel WBS of a project: nodes, reordering, critical path recalculation and the Kanban board.
 */
final class ProjectWbsController extends Controller
{
    public function __construct(
        private readonly ProjectWbsService $wbs,
        private readonly ProjectWbsScheduleService $schedule,
    ) {}

    /**
     * Adds a node to the WBS (any depth); WBS codes, roll-up and critical path are recomputed.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/wbs-nodes', operationId: 'projectsWbsNodesCreate', summary: 'Create WBS node', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['title'], properties: [
            new OA\Property(property: 'title', type: 'string', maxLength: 255),
            new OA\Property(property: 'parent_node_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'sort_order', type: 'integer', minimum: 1, nullable: true),
            new OA\Property(property: 'node_type', type: 'string', enum: ['phase', 'deliverable', 'work_package', 'milestone']),
            new OA\Property(property: 'status', type: 'string', enum: ['backlog', 'todo', 'in_progress', 'review', 'done', 'blocked']),
            new OA\Property(property: 'planned_hours', type: 'number'),
            new OA\Property(property: 'planned_cost', type: 'number'),
            new OA\Property(property: 'planned_revenue', type: 'number'),
            new OA\Property(property: 'start_date', type: 'string', format: 'date'),
            new OA\Property(property: 'end_date', type: 'string', format: 'date'),
            new OA\Property(property: 'percent_complete', type: 'number'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Node created'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreWbsNodeRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $node = $this->wbs->createNode($project, $request->validated(), (string) $request->user()?->getKey());

        return response()->json(['message' => 'WBS node created', 'data' => new ProjectWbsNodeResource($node)], 201);
    }

    /**
     * Edits or moves a node.
     */
    #[OA\Put(path: '/api/v1/projects/{id}/wbs-nodes/{nodeId}', operationId: 'projectsWbsNodesUpdate', summary: 'Update or move WBS node', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'nodeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'parent_node_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'sort_order', type: 'integer', minimum: 1),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Node updated'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function update(string $id, string $nodeId, UpdateWbsNodeRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $node = $this->node($project, $nodeId);
        $updated = $this->wbs->updateNode($project, $node, $request->validated(), (string) $request->user()?->getKey());

        return response()->json(['message' => 'WBS node updated', 'data' => new ProjectWbsNodeResource($updated->refresh())]);
    }

    /**
     * Deletes a node with its subtree and dependencies; WBS codes are renumbered.
     */
    #[OA\Delete(path: '/api/v1/projects/{id}/wbs-nodes/{nodeId}', operationId: 'projectsWbsNodesDelete', summary: 'Delete WBS node', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'nodeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Node deleted'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function destroy(string $id, string $nodeId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $this->wbs->deleteNode($project, $this->node($project, $nodeId), (string) request()->user()?->getKey());

        return response()->json(['message' => 'WBS node deleted']);
    }

    /**
     * Moves several nodes at once (drag and drop of the WBS tree).
     */
    #[OA\Patch(path: '/api/v1/projects/{id}/wbs-nodes/reorder', operationId: 'projectsWbsNodesReorder', summary: 'Reorder WBS nodes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['nodes'], properties: [
            new OA\Property(property: 'nodes', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'parent_node_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'sort_order', type: 'integer', minimum: 1),
            ])),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'WBS in its new order'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function reorder(string $id, ReorderWbsNodesRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $nodes = $this->wbs->reorder($project, $request->validated('nodes'), (string) $request->user()?->getKey());

        return response()->json(['message' => 'WBS reordered', 'data' => ProjectWbsNodeResource::collection($nodes->sortBy('wbs_code', SORT_NATURAL)->values())]);
    }

    /**
     * Full recalculation: DAG check, forward and backward pass, floats, critical path, cascade of dates and roll-up.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/cpm/recalculate', operationId: 'projectsCpmRecalculate', summary: 'Recalculate the critical path', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Schedule summary'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function recalculate(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);
        $this->wbs->reindex($project);

        return response()->json(['message' => 'Schedule recalculated', 'data' => $this->schedule->recalculate($project)]);
    }

    /**
     * Work items of the WBS grouped by workflow status.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/kanban', operationId: 'projectsKanban', summary: 'Kanban board of the WBS', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Columns with their work items'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function kanban(string $id, GetProjectWbsKanbanQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->handle(Project::findOrFail($id))]);
    }

    private function node(Project $project, string $nodeId): ProjectWbsNode
    {
        return ProjectWbsNode::query()->where('project_id', $project->id)->findOrFail($nodeId);
    }
}
