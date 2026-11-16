<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\RiskControlsRequest;
use Modules\Governance\Http\Requests\RiskRequest;
use Modules\Governance\Http\Requests\TransitionRequest;
use Modules\Governance\Http\Resources\RiskResource;
use Modules\Governance\Models\GovRisk;
use Modules\Governance\Services\RiskService;
use OpenApi\Attributes as OA;

/**
 * The risk register: scoring, treatment, links to controls and the status flow.
 */
final class RiskController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly RiskService $risks) {}

    #[OA\Get(path: '/api/v1/governance/risks', operationId: 'listGovRisks', description: 'Paginated risks, highest inherent score first. Filters: status, category, rating (by inherent score: low 1-4, medium 5-9, high 10-14, critical 15-25), owner_id, review_overdue=1, search (code or title).', summary: 'List risks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['identified', 'assessed', 'treating', 'accepted', 'closed'])),
        new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['strategic', 'operational', 'financial', 'compliance', 'it_security', 'legal', 'reputational', 'environmental'])),
        new OA\Parameter(name: 'rating', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'critical'])),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'review_overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Risks', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovRisk'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GovRisk::class);
        $ranges = ['low' => [1, 4], 'medium' => [5, 9], 'high' => [10, 14], 'critical' => [15, 25]];

        return RiskResource::collection(
            GovRisk::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('category'), static fn ($query) => $query->where('category', (string) $request->query('category')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when(isset($ranges[(string) $request->query('rating')]), static fn ($query) => $query->whereBetween('inherent_score', $ranges[(string) $request->query('rating')]))
                ->when($request->boolean('review_overdue'), static fn ($query) => $query->whereIn('status', GovRisk::LiveStatuses)->whereDate('next_review_date', '<', now()->toDateString()))
                ->when($request->filled('search'), static fn ($query) => $query->where(static fn ($search) => $search->where('title', 'like', '%'.$request->query('search').'%')->orWhere('code', 'like', '%'.$request->query('search').'%')))
                ->orderByDesc('inherent_score')
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/risks', operationId: 'storeGovRisk', description: 'Registers a risk. The inherent score is likelihood x impact (1-5 each); the residual score is optional and cannot exceed the inherent one on either axis. The review date defaults to 3, 6 or 12 months by rating.', summary: 'Register a risk', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovRiskRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Risk registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovRisk')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function store(RiskRequest $request): JsonResponse
    {
        $this->authorize('create', GovRisk::class);

        return (new RiskResource($this->risks->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/governance/risks/{id}', operationId: 'showGovRisk', description: 'A risk with the controls that mitigate it.', summary: 'Show a risk', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Risk', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovRisk')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function show(string $id): RiskResource
    {
        $risk = $this->find($id);
        $this->authorize('view', $risk);

        return new RiskResource($risk->load('controls'));
    }

    #[OA\Put(path: '/api/v1/governance/risks/{id}', operationId: 'updateGovRisk', description: 'Changes a risk that is not closed. The scores are recomputed.', summary: 'Update a risk', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovRiskRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Risk updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovRisk')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(RiskRequest $request, string $id): RiskResource
    {
        $risk = $this->find($id);
        $this->authorize('update', $risk);

        return new RiskResource($this->risks->update($risk, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/governance/risks/{id}', operationId: 'deleteGovRisk', description: 'Deletes a risk that no open finding refers to.', summary: 'Delete a risk', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 204, description: 'Risk deleted'),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $risk = $this->find($id);
        $this->authorize('delete', $risk);
        $this->risks->delete($risk);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/governance/risks/{id}/transition', operationId: 'transitionGovRisk', description: 'Moves the risk through identified, assessed, treating, accepted and closed. Assessing needs an owner, treating needs an active linked control, accepting needs notes (the justification) and the authority of an owner, an administrator or a member granted governance.approve.', summary: 'Change the status of a risk', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovTransitionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Risk updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovRisk')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function transition(TransitionRequest $request, string $id): RiskResource
    {
        $risk = $this->find($id);
        $this->authorize('update', $risk);
        $to = (string) $request->validated('status');

        if ($to === 'accepted') {
            $this->authorize('approve', $risk);
        }

        return new RiskResource($this->risks->transition($risk, $to, $request->validated('notes'), $this->userId()));
    }

    #[OA\Put(path: '/api/v1/governance/risks/{id}/controls', operationId: 'syncGovRiskControls', description: 'Replaces the controls that mitigate the risk.', summary: 'Link controls to a risk', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovRiskControlsRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Risk with its controls', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovRisk')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function syncControls(RiskControlsRequest $request, string $id): RiskResource
    {
        $risk = $this->find($id);
        $this->authorize('update', $risk);

        return new RiskResource($this->risks->syncControls($risk, array_values($request->validated('control_ids')))->load('controls'));
    }

    private function find(string $id): GovRisk
    {
        return GovRisk::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
