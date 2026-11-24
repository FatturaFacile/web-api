<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Http\Requests\StoreProjectTemplateRequest;
use Modules\Projects\Http\Resources\ProjectTemplateResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTemplate;
use Modules\Projects\Services\ProjectTemplateService;
use OpenApi\Attributes as OA;

/**
 * Reusable project templates (Mod 078): list, save from an existing project or from a structure, and retire.
 */
final class ProjectTemplateController extends Controller
{
    /**
     * Active templates of the company.
     */
    #[OA\Get(path: '/api/v1/projects/templates', operationId: 'projectsTemplatesList', summary: 'List project templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        responses: [
            new OA\Response(response: 200, description: 'Active project templates'),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
        ])]
    public function index(): JsonResponse
    {
        $templates = ProjectTemplate::query()->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => ProjectTemplateResource::collection($templates)]);
    }

    /**
     * Saves a template from the WBS of a project (`source_project_id`) or from an explicit `structure`.
     */
    #[OA\Post(path: '/api/v1/projects/templates', operationId: 'projectsTemplatesCreate', summary: 'Save a project template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code', 'name'], properties: [
            new OA\Property(property: 'code', type: 'string', maxLength: 50),
            new OA\Property(property: 'name', type: 'string', maxLength: 255),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'source_project_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'structure', type: 'object', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Template saved'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(StoreProjectTemplateRequest $request, ProjectTemplateService $service, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Project::class);
        $data = $request->validated();
        $tenantId = (string) $tenantContext->getTenantId();

        if (isset($data['source_project_id'])) {
            $template = $service->captureFromProject(Project::query()->findOrFail($data['source_project_id']), $data['code'], $data['name'], $data['description'] ?? null, $tenantId);
        } else {
            $template = $service->create($data['code'], $data['name'], $data['description'] ?? null, $data['structure'], $tenantId);
        }

        return response()->json(['message' => 'Project template saved', 'data' => new ProjectTemplateResource($template)], 201);
    }

    /**
     * Retires a template: projects already opened from it are not affected.
     */
    #[OA\Delete(path: '/api/v1/projects/templates/{templateId}', operationId: 'projectsTemplatesDelete', summary: 'Delete a project template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'templateId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Template deleted'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function destroy(string $templateId): JsonResponse
    {
        $this->authorize('create', Project::class);
        ProjectTemplate::query()->findOrFail($templateId)->delete();

        return response()->json(null, 204);
    }
}
