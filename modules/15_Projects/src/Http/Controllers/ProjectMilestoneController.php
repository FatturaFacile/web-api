<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Http\Requests\StoreMilestoneRequest;
use Modules\Projects\Http\Resources\ProjectMilestoneResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Services\ProjectMilestoneService;
use OpenApi\Attributes as OA;

/**
 * Milestones of a project: planning, completion and their billing status.
 */
final class ProjectMilestoneController extends Controller
{
    public function __construct(private readonly ProjectMilestoneService $milestones) {}

    /**
     * Milestones of the project in date order.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/milestones', operationId: 'projectsMilestonesList', summary: 'List project milestones', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Milestones', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsMilestone'))])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function index(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);

        return response()->json(['data' => ProjectMilestoneResource::collection(ProjectMilestone::query()->where('project_id', $project->id)->orderBy('milestone_date')->limit(500)->get())]);
    }

    /**
     * Adds a milestone.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/milestones', operationId: 'projectsMilestonesCreate', summary: 'Create project milestone', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreMilestoneRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Milestone created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsMilestone')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreMilestoneRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        return response()->json(['data' => new ProjectMilestoneResource($this->milestones->create($project, $request->validated()))], 201);
    }

    /**
     * Changes a milestone that is not billed yet.
     */
    #[OA\Put(path: '/api/v1/projects/milestones/{milestoneId}', operationId: 'projectsMilestonesUpdate', summary: 'Update project milestone', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsMilestoneId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreMilestoneRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Milestone updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsMilestone')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function update(string $milestoneId, StoreMilestoneRequest $request): JsonResponse
    {
        $milestone = ProjectMilestone::findOrFail($milestoneId);
        $this->authorize('manage', Project::findOrFail($milestone->project_id));

        return response()->json(['data' => new ProjectMilestoneResource($this->milestones->update($milestone, $request->validated()))]);
    }

    /**
     * Marks the milestone as reached.
     */
    #[OA\Post(path: '/api/v1/projects/milestones/{milestoneId}/complete', operationId: 'projectsMilestonesComplete', summary: 'Complete project milestone', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsMilestoneId')],
        responses: [
            new OA\Response(response: 200, description: 'Milestone completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsMilestone')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function complete(string $milestoneId, Request $request): JsonResponse
    {
        $milestone = ProjectMilestone::findOrFail($milestoneId);
        $this->authorize('manage', Project::findOrFail($milestone->project_id));

        return response()->json(['data' => new ProjectMilestoneResource($this->milestones->complete($milestone, (string) $request->user()?->getKey()))]);
    }
}
