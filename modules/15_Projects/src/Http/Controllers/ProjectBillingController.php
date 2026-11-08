<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\BillProjectRequest;
use Modules\Projects\Http\Resources\ProjectBillingEventResource;
use Modules\Projects\Http\Resources\ProjectInvoiceDraftResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingEvent;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Services\ProjectBillingService;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Billing of a project into draft sales invoices (milestones, time and materials) and its billing history.
 */
final class ProjectBillingController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(private readonly ProjectBillingService $billing) {}

    /**
     * Invoices a completed milestone once, as a draft invoice of the project customer.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/billing/milestones/{milestoneId}', operationId: 'projectsBillMilestone', summary: 'Bill project milestone', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(ref: '#/components/parameters/ProjectsMilestoneId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsBillProjectRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Draft invoice created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsInvoiceDraft')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function milestone(string $id, string $milestoneId, BillProjectRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $milestone = ProjectMilestone::query()->whereKey($milestoneId)->where('project_id', $project->id)->firstOrFail();
        $invoice = $this->billing->billMilestone($project, $milestone, Money::of($request->validated('tax_rate')), (string) $request->user()?->getKey());

        return response()->json(['message' => 'Draft invoice created', 'data' => new ProjectInvoiceDraftResource($invoice)], 201);
    }

    /**
     * Invoices the approved billable hours and costs up to a date, as one draft invoice.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/billing/time-and-materials', operationId: 'projectsBillTimeAndMaterials', summary: 'Bill project time and materials', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsBillProjectRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Draft invoice created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsInvoiceDraft')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function timeAndMaterials(string $id, BillProjectRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $upTo = (string) ($request->validated('up_to_date') ?? now()->toDateString());
        $invoice = $this->billing->billTimeAndMaterials($project, $upTo, Money::of($request->validated('tax_rate')), (string) $request->user()?->getKey());

        return response()->json(['message' => 'Draft invoice created', 'data' => new ProjectInvoiceDraftResource($invoice)], 201);
    }

    /**
     * Billing runs of the project with their invoices.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/billing-events', operationId: 'projectsBillingEvents', summary: 'List project billing events', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Billing events', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsBillingEvent')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function events(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);

        return $this->paginated(ProjectBillingEvent::query()->where('project_id', $project->id)->orderByDesc('created_at')->paginate($this->perPage($request)), ProjectBillingEventResource::class);
    }
}
