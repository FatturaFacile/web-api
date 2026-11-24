<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\ActionRequest;
use Modules\Governance\Http\Requests\TransitionRequest;
use Modules\Governance\Http\Resources\ActionResource;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Services\ActionService;
use OpenApi\Attributes as OA;

/**
 * Remediation actions of the findings, with overdue detection and the verification step.
 */
final class ActionController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly ActionService $actions) {}

    #[OA\Get(path: '/api/v1/governance/actions', operationId: 'listGovActions', description: 'Paginated remediation actions by due date. Filters: status, finding_id, owner_id, mine=1 (actions of the current user), overdue=1 (open or in progress past their due date).', summary: 'List remediation actions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'in_progress', 'completed', 'verified', 'cancelled'])),
        new OA\Parameter(name: 'finding_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'mine', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Actions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovAction'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GovAction::class);

        return ActionResource::collection(
            GovAction::query()
                ->where('tenant_id', $this->tenantId())
                ->with('finding:id,code,title,severity')
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('finding_id'), static fn ($query) => $query->where('finding_id', (string) $request->query('finding_id')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when($request->boolean('mine'), fn ($query) => $query->where('owner_id', $this->userId()))
                ->when($request->boolean('overdue'), static fn ($query) => $query->whereIn('status', GovAction::OpenStatuses)->whereDate('due_date', '<', now()->toDateString()))
                ->orderBy('due_date')
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Get(path: '/api/v1/governance/actions/{id}', operationId: 'showGovAction', description: 'A remediation action.', summary: 'Show a remediation action', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Action', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAction')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function show(string $id): ActionResource
    {
        $action = $this->find($id);
        $this->authorize('view', $action);

        return new ActionResource($action->load('finding:id,code,title,severity'));
    }

    #[OA\Put(path: '/api/v1/governance/actions/{id}', operationId: 'updateGovAction', description: 'Changes the title, description, owner or due date of an action that is open or in progress (the due date keeps the term of the severity of the finding).', summary: 'Update a remediation action', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovActionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Action updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAction')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(ActionRequest $request, string $id): ActionResource
    {
        $action = $this->find($id);
        $this->authorize('update', $action);

        return new ActionResource($this->actions->update($action, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/governance/actions/{id}/transition', operationId: 'transitionGovAction', description: 'Starts, completes (the owner or a manager), verifies (an owner, an administrator or a member granted governance.approve, never the person who completed it), sends back to in progress from completed (notes required) or cancels (notes required). Every change realigns the status of the finding.', summary: 'Change the status of a remediation action', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovTransitionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Action updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovAction')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function transition(TransitionRequest $request, string $id): ActionResource
    {
        $action = $this->find($id);
        $to = (string) $request->validated('status');

        if ($to === 'verified' || ($to === 'in_progress' && $action->status === 'completed')) {
            $this->authorize('approve', $action);
        } else {
            $this->authorize('progress', $action);
        }

        return new ActionResource($this->actions->transition($action, $to, $request->validated('notes'), $this->userId()));
    }

    private function find(string $id): GovAction
    {
        return GovAction::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
