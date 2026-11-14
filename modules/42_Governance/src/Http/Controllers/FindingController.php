<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\ActionRequest;
use Modules\Governance\Http\Requests\FindingRequest;
use Modules\Governance\Http\Requests\TransitionRequest;
use Modules\Governance\Http\Resources\ActionResource;
use Modules\Governance\Http\Resources\FindingResource;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Models\GovFinding;
use Modules\Governance\Services\ActionService;
use Modules\Governance\Services\FindingService;
use OpenApi\Attributes as OA;

/**
 * Audit findings with severity, recommendation and their remediation actions.
 */
final class FindingController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly FindingService $findings, private readonly ActionService $actions) {}

    #[OA\Get(path: '/api/v1/governance/findings', operationId: 'listGovFindings', description: 'Paginated findings, most severe first. Filters: status, severity, source, audit_id, control_id, owner_id, open=1 (open, in remediation or resolved), overdue=1 (a live action is past its due date), search (code or title).', summary: 'List findings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'remediation', 'resolved', 'closed', 'risk_accepted'])),
        new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'critical'])),
        new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['audit', 'control_test', 'self_assessment', 'external'])),
        new OA\Parameter(name: 'audit_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'control_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'open', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Findings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovFinding'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GovFinding::class);

        return FindingResource::collection(
            GovFinding::query()
                ->where('tenant_id', $this->tenantId())
                ->withCount('actions')
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('severity'), static fn ($query) => $query->where('severity', (string) $request->query('severity')))
                ->when($request->filled('source'), static fn ($query) => $query->where('source', (string) $request->query('source')))
                ->when($request->filled('audit_id'), static fn ($query) => $query->where('audit_id', (string) $request->query('audit_id')))
                ->when($request->filled('control_id'), static fn ($query) => $query->where('control_id', (string) $request->query('control_id')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when($request->boolean('open'), static fn ($query) => $query->whereIn('status', GovFinding::OpenStatuses))
                ->when($request->boolean('overdue'), static fn ($query) => $query->whereHas('actions', static fn ($actions) => $actions->whereIn('status', GovAction::OpenStatuses)->whereDate('due_date', '<', now()->toDateString())))
                ->when($request->filled('search'), static fn ($query) => $query->where(static fn ($search) => $search->where('title', 'like', '%'.$request->query('search').'%')->orWhere('code', 'like', '%'.$request->query('search').'%')))
                ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/findings', operationId: 'storeGovFinding', description: 'Raises a finding. A finding from an audit needs the audit (in progress or reporting), one from a control test needs the control.', summary: 'Raise a finding', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovFindingRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Finding raised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovFinding')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function store(FindingRequest $request): JsonResponse
    {
        $this->authorize('create', GovFinding::class);

        return (new FindingResource($this->findings->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/governance/findings/{id}', operationId: 'showGovFinding', description: 'A finding with its remediation actions.', summary: 'Show a finding', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Finding', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovFinding')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function show(string $id): FindingResource
    {
        $finding = $this->find($id);
        $this->authorize('view', $finding);

        return new FindingResource($finding->load(['actions' => static fn ($query) => $query->orderBy('due_date')->orderBy('code')])->loadCount('actions'));
    }

    #[OA\Put(path: '/api/v1/governance/findings/{id}', operationId: 'updateGovFinding', description: 'Changes a finding that is open, in remediation or resolved. The source and the audit never change.', summary: 'Update a finding', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovFindingRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Finding updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovFinding')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(FindingRequest $request, string $id): FindingResource
    {
        $finding = $this->find($id);
        $this->authorize('update', $finding);

        return new FindingResource($this->findings->update($finding, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/governance/findings/{id}/transition', operationId: 'transitionGovFinding', description: 'Remediation and resolved follow the actions automatically. Manual changes: resolved to closed (every action verified; notes are the closure notes), accepting the risk (notes are the reason; it cancels the live actions) and reopening. Accepting a risk and closing a high or critical finding need the authority of an owner, an administrator or a member granted governance.approve.', summary: 'Change the status of a finding', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovTransitionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Finding updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovFinding')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function transition(TransitionRequest $request, string $id): FindingResource
    {
        $finding = $this->find($id);
        $this->authorize('update', $finding);
        $to = (string) $request->validated('status');

        if ($to === 'risk_accepted' || ($to === 'closed' && in_array($finding->severity, ['high', 'critical'], true))) {
            $this->authorize('approve', $finding);
        }

        return new FindingResource($this->findings->transition($finding, $to, $request->validated('notes'), $this->userId())->loadCount('actions'));
    }

    #[OA\Post(path: '/api/v1/governance/findings/{id}/actions', operationId: 'storeGovFindingAction', description: 'Raises a remediation action on a finding that is open, in remediation or resolved, with an owner and a due date no later than the term of the severity (critical 30 days, high 90, medium 180, low 365). The finding moves to remediation.', summary: 'Raise a remediation action', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovActionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 201, description: 'Action raised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAction')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function storeAction(ActionRequest $request, string $id): JsonResponse
    {
        $finding = $this->find($id);
        $this->authorize('update', $finding);

        return (new ActionResource($this->actions->create($finding, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function find(string $id): GovFinding
    {
        return GovFinding::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
