<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\ChangeProjectStatusRequest;
use Modules\Projects\Http\Requests\StoreProjectFromTemplateRequest;
use Modules\Projects\Http\Resources\ProjectPortfolioItemResource;
use Modules\Projects\Http\Resources\ProjectResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTemplate;
use Modules\Projects\Queries\GetProjectPortfolioQuery;
use Modules\Projects\Services\ProjectFromTemplateService;
use Modules\Projects\Services\ProjectStatusService;
use OpenApi\Attributes as OA;

/**
 * Portfolio overview, project creation from a template and the status change endpoint.
 */
final class ProjectPortfolioController extends Controller
{
    use PaginatesProjectRecords;

    /**
     * Portfolio of the projects with filters, pagination and KPIs of the filtered set.
     */
    #[OA\Get(path: '/api/v1/projects/portfolio', operationId: 'projectsPortfolio', summary: 'Project portfolio overview', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'One status or a comma separated list', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'project_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'billing_method', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'project_manager_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'portfolio_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'health_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['green', 'yellow', 'red'])),
            new OA\Parameter(name: 'has_public_codes', in: 'query', required: false, description: 'Only projects with a CUP or a CIG', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches number, name, CUP or CIG', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Projects ending on or after this date', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Projects starting on or before this date', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Portfolio rows, pagination meta and KPIs'),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
        ])]
    public function portfolio(Request $request, GetProjectPortfolioQuery $query): JsonResponse
    {
        $filters = $request->only(['status', 'project_type', 'billing_method', 'contact_id', 'project_manager_user_id', 'portfolio_id', 'health_status', 'has_public_codes', 'search', 'from', 'to']);
        $filters['has_public_codes'] = $request->boolean('has_public_codes');
        $result = $query->handle($filters, $this->perPage($request, 15));
        $page = $result['items'];

        return response()->json([
            'data' => ProjectPortfolioItemResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'kpis' => $result['kpis'],
            ],
        ]);
    }

    /**
     * Opens a project from a template: WBS hierarchy, relative dates, dependencies and roles.
     */
    #[OA\Post(path: '/api/v1/projects/from-template', operationId: 'projectsFromTemplate', summary: 'Create project from template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['template_id', 'contact_id', 'project_number', 'project_type', 'billing_method', 'planned_start_date', 'project_manager_user_id'], properties: [
            new OA\Property(property: 'template_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'project_number', type: 'string'),
            new OA\Property(property: 'name', type: 'string', nullable: true),
            new OA\Property(property: 'project_type', type: 'string'),
            new OA\Property(property: 'billing_method', type: 'string'),
            new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
            new OA\Property(property: 'planned_end_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'project_manager_user_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'contract_value', type: 'number'),
            new OA\Property(property: 'cup_code', type: 'string', nullable: true),
            new OA\Property(property: 'cig_code', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Project created with its WBS'),
            new OA\Response(response: 401, ref: '#/components/responses/ProjectsUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function fromTemplate(StoreProjectFromTemplateRequest $request, ProjectFromTemplateService $service, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Project::class);
        $data = $request->validated();
        $template = ProjectTemplate::query()->where('is_active', true)->findOrFail($data['template_id']);

        $project = $service->instantiate($template, $data, (string) $request->user()?->getKey(), (string) $tenantContext->getTenantId());

        return response()->json(['message' => 'Project created from template', 'data' => new ProjectResource($project)], 201);
    }

    /**
     * Moves the project to a target status (planned, active, on_hold, completed, cancelled).
     */
    #[OA\Patch(path: '/api/v1/projects/{id}/status', operationId: 'projectsChangeStatus', summary: 'Change project status', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ProjectStatusService::Targets),
            new OA\Property(property: 'reason', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Project with its new status'),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function changeStatus(string $id, ChangeProjectStatusRequest $request, ProjectStatusService $service): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $updated = $service->change($project, (string) $request->validated('status'), (string) $request->user()?->getKey(), $request->validated('reason'));

        return response()->json(['message' => 'Project status changed', 'data' => new ProjectResource($updated)]);
    }
}
