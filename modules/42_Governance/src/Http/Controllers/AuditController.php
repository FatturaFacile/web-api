<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\AuditRequest;
use Modules\Governance\Http\Requests\TransitionRequest;
use Modules\Governance\Http\Resources\AuditResource;
use Modules\Governance\Models\GovAudit;
use Modules\Governance\Services\AuditService;
use OpenApi\Attributes as OA;

/**
 * Internal audits with scope, team, dates and the fieldwork status flow.
 */
final class AuditController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly AuditService $audits) {}

    #[OA\Get(path: '/api/v1/governance/audits', operationId: 'listGovAudits', description: 'Paginated audits by planned start. Filters: status, audit_plan_id, audit_type, late=1 (planned audits past their planned start).', summary: 'List audits', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['planned', 'in_progress', 'reporting', 'closed', 'cancelled'])),
        new OA\Parameter(name: 'audit_plan_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'audit_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['process', 'compliance', 'it', 'financial', 'follow_up'])),
        new OA\Parameter(name: 'late', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Audits', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovAudit'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GovAudit::class);

        return AuditResource::collection(
            GovAudit::query()
                ->where('tenant_id', $this->tenantId())
                ->withCount('findings')
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('audit_plan_id'), static fn ($query) => $query->where('audit_plan_id', (string) $request->query('audit_plan_id')))
                ->when($request->filled('audit_type'), static fn ($query) => $query->where('audit_type', (string) $request->query('audit_type')))
                ->when($request->boolean('late'), static fn ($query) => $query->where('status', 'planned')->whereDate('planned_start', '<', now()->toDateString()))
                ->orderBy('planned_start')
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/audits', operationId: 'storeGovAudit', description: 'Plans an audit with scope, lead auditor, team and dates, optionally under an audit plan that is not closed (the audit must start in the year of the plan). The lead auditor is removed from the team list.', summary: 'Plan an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovAuditRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Audit planned', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAudit')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function store(AuditRequest $request): JsonResponse
    {
        $this->authorize('create', GovAudit::class);

        return (new AuditResource($this->audits->createAudit($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/governance/audits/{id}', operationId: 'showGovAudit', description: 'An audit with the number of its findings.', summary: 'Show an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Audit', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAudit')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function show(string $id): AuditResource
    {
        $audit = $this->find($id);
        $this->authorize('view', $audit);

        return new AuditResource($audit->loadCount('findings'));
    }

    #[OA\Put(path: '/api/v1/governance/audits/{id}', operationId: 'updateGovAudit', description: 'A planned audit can be changed freely; once it has started only the team and the conclusion can change; a closed or cancelled audit is locked.', summary: 'Update an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovAuditRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Audit updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAudit')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(AuditRequest $request, string $id): AuditResource
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);

        return new AuditResource($this->audits->updateAudit($audit, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/governance/audits/{id}/transition', operationId: 'transitionGovAudit', description: 'Starts the fieldwork (the plan of the audit must be approved), moves to reporting, closes with a conclusion (no high or critical finding may still be open) or cancels with a reason in notes. Reporting can go back to in progress.', summary: 'Change the status of an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovTransitionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Audit updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAudit')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function transition(TransitionRequest $request, string $id): AuditResource
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);

        return new AuditResource($this->audits->transitionAudit($audit, (string) $request->validated('status'), $request->validated('notes'), $this->userId())->loadCount('findings'));
    }

    private function find(string $id): GovAudit
    {
        return GovAudit::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
