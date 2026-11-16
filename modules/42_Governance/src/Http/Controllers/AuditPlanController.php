<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\AuditPlanRequest;
use Modules\Governance\Http\Requests\TransitionRequest;
use Modules\Governance\Http\Resources\AuditPlanResource;
use Modules\Governance\Models\GovAuditPlan;
use Modules\Governance\Services\AuditService;
use OpenApi\Attributes as OA;

/**
 * The annual internal audit plans.
 */
final class AuditPlanController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly AuditService $audits) {}

    #[OA\Get(path: '/api/v1/governance/audit-plans', operationId: 'listGovAuditPlans', description: 'The audit plans, latest year first. Filter: status.', summary: 'List audit plans', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'approved', 'closed'])),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Audit plans', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovAuditPlan'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GovAuditPlan::class);

        return AuditPlanResource::collection(
            GovAuditPlan::query()
                ->where('tenant_id', $this->tenantId())
                ->withCount('audits')
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->orderByDesc('year')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/audit-plans', operationId: 'storeGovAuditPlan', description: 'Creates the draft plan of a year. There is one plan per year.', summary: 'Create an audit plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovAuditPlanRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Plan created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAuditPlan')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function store(AuditPlanRequest $request): JsonResponse
    {
        $this->authorize('create', GovAuditPlan::class);

        return (new AuditPlanResource($this->audits->createPlan($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/governance/audit-plans/{id}', operationId: 'showGovAuditPlan', description: 'A plan with its audits.', summary: 'Show an audit plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Audit plan', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAuditPlan')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function show(string $id): AuditPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('view', $plan);

        return new AuditPlanResource($plan->load('audits')->loadCount('audits'));
    }

    #[OA\Put(path: '/api/v1/governance/audit-plans/{id}', operationId: 'updateGovAuditPlan', description: 'Changes the title, description or year of a plan that is not closed (the year cannot change once the plan has audits).', summary: 'Update an audit plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovAuditPlanRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Plan updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAuditPlan')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(AuditPlanRequest $request, string $id): AuditPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('update', $plan);

        return new AuditPlanResource($this->audits->updatePlan($plan, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/governance/audit-plans/{id}/transition', operationId: 'transitionGovAuditPlan', description: 'Approves a draft plan (it needs at least one audit and the authority of an owner, an administrator or a member granted governance.approve) or closes an approved plan once every audit is closed or cancelled.', summary: 'Approve or close an audit plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovTransitionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Plan updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAuditPlan')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function transition(TransitionRequest $request, string $id): AuditPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('approve', $plan);

        return new AuditPlanResource($this->audits->transitionPlan($plan, (string) $request->validated('status'), $this->userId()));
    }

    private function find(string $id): GovAuditPlan
    {
        return GovAuditPlan::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
