<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Http\Requests\StoreWbsDependencyRequest;
use Modules\Projects\Http\Requests\UpdateWbsDependencyRequest;
use Modules\Projects\Http\Resources\ProjectWbsDependencyResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Services\ProjectWbsService;
use OpenApi\Attributes as OA;

/**
 * Precedence links (FS, SS, FF, SF with lag or lead) between the leaf nodes of the WBS of a project.
 */
final class ProjectWbsDependencyController extends Controller
{
    public function __construct(private readonly ProjectWbsService $wbs) {}

    /**
     * Links two nodes; refused when it would close a cycle. The schedule is recalculated.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/dependencies', operationId: 'projectsDependenciesCreate', summary: 'Create WBS dependency', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['predecessor_node_id', 'successor_node_id'], properties: [
            new OA\Property(property: 'predecessor_node_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'successor_node_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'dependency_type', type: 'string', enum: ['FS', 'SS', 'FF', 'SF'], default: 'FS'),
            new OA\Property(property: 'lag_days', type: 'integer', default: 0),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Dependency created'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreWbsDependencyRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $dependency = $this->wbs->createDependency($project, $request->validated(), (string) $request->user()?->getKey());

        return response()->json(['message' => 'Dependency created', 'data' => new ProjectWbsDependencyResource($dependency)], 201);
    }

    /**
     * Changes type and lag of a link.
     */
    #[OA\Put(path: '/api/v1/projects/{id}/dependencies/{depId}', operationId: 'projectsDependenciesUpdate', summary: 'Update WBS dependency', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'depId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'dependency_type', type: 'string', enum: ['FS', 'SS', 'FF', 'SF']),
            new OA\Property(property: 'lag_days', type: 'integer'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Dependency updated'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function update(string $id, string $depId, UpdateWbsDependencyRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $dependency = $this->wbs->updateDependency($project, $this->dependency($project, $depId), $request->validated(), (string) $request->user()?->getKey());

        return response()->json(['message' => 'Dependency updated', 'data' => new ProjectWbsDependencyResource($dependency)]);
    }

    /**
     * Removes a link; the schedule is recalculated.
     */
    #[OA\Delete(path: '/api/v1/projects/{id}/dependencies/{depId}', operationId: 'projectsDependenciesDelete', summary: 'Delete WBS dependency', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'depId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Dependency deleted'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function destroy(string $id, string $depId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $this->wbs->deleteDependency($project, $this->dependency($project, $depId), (string) request()->user()?->getKey());

        return response()->json(['message' => 'Dependency deleted']);
    }

    private function dependency(Project $project, string $dependencyId): ProjectWbsDependency
    {
        return ProjectWbsDependency::query()->where('project_id', $project->id)->findOrFail($dependencyId);
    }
}
