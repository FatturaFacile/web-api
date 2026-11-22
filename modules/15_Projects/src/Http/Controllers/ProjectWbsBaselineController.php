<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Http\Requests\StoreWbsBaselineRequest;
use Modules\Projects\Http\Resources\ProjectWbsBaselineResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsBaseline;
use Modules\Projects\Services\ProjectWbsBaselineService;
use OpenApi\Attributes as OA;

/**
 * Numbered schedule baselines of the WBS and the variance of the live plan against them.
 */
final class ProjectWbsBaselineController extends Controller
{
    public function __construct(private readonly ProjectWbsBaselineService $baselines) {}

    /**
     * Baselines of the project, oldest first.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/baselines', operationId: 'projectsBaselinesList', summary: 'List WBS baselines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Baselines'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function index(string $id): JsonResponse
    {
        $project = Project::findOrFail($id);

        return response()->json(['data' => ProjectWbsBaselineResource::collection($project->wbsBaselines()->get())]);
    }

    /**
     * Freezes the current WBS as the next baseline.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/baselines', operationId: 'projectsBaselinesCreate', summary: 'Capture a WBS baseline', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string', maxLength: 150)])),
        responses: [
            new OA\Response(response: 201, description: 'Baseline captured'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreWbsBaselineRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $baseline = $this->baselines->capture($project, $request->validated('name'), (string) $request->user()?->getKey());

        return response()->json(['message' => 'Baseline captured', 'data' => new ProjectWbsBaselineResource($baseline)], 201);
    }

    /**
     * Schedule, effort, cost and revenue variance of the live WBS against a baseline.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/baselines/{baselineId}/variance', operationId: 'projectsBaselinesVariance', summary: 'Variance against a WBS baseline', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'baselineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Variance per node and in total'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function variance(string $id, string $baselineId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $baseline = ProjectWbsBaseline::query()->where('project_id', $project->id)->findOrFail($baselineId);

        return response()->json(['data' => $this->baselines->variance($project, $baseline)]);
    }
}
