<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\OverrideDecisionRequest;
use Modules\Budgeting\Http\Requests\OverrideRequest;
use Modules\Budgeting\Http\Resources\AuditEventResource;
use Modules\Budgeting\Http\Resources\OverrideResource;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetOverride;
use Modules\Budgeting\Services\OverrideService;
use OpenApi\Attributes as OA;

/**
 * Override (deroga) requests against the spend lock: anyone who writes budget data requests, the CFO (owners,
 * administrators and members granted budgeting.override.approve) decides, and the requester never approves their own.
 */
final class OverrideController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly OverrideService $overrides) {}

    #[OA\Get(path: '/api/v1/budgeting/spend-control/overrides', operationId: 'listBudgetOverrides', description: 'Override requests, newest first. Filters: status, scope, source_id.', summary: 'List override requests', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Overrides', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BudgetOverride::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(BudgetOverride::Statuses)], 'scope' => ['nullable', Rule::in(BudgetOverride::Scopes)], 'source_id' => ['nullable', 'uuid']]);

        return OverrideResource::collection(
            BudgetOverride::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['scope']), static fn ($query) => $query->where('scope', $filters['scope']))
                ->when(isset($filters['source_id']), static fn ($query) => $query->where('source_id', $filters['source_id']))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/budgeting/spend-control/overrides', operationId: 'requestBudgetOverride', description: 'Ask the CFO to let a document, or an account, cost centre or project for a while, spend beyond the budget. The reason is kept with the request.', summary: 'Request an override', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 201, description: 'Override requested', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function store(OverrideRequest $request): JsonResponse
    {
        $this->authorize('create', BudgetOverride::class);

        return (new OverrideResource($this->overrides->request($this->tenantId(), $this->userId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/budgeting/spend-control/overrides/{id}', operationId: 'showBudgetOverride', description: 'An override request with its decision.', summary: 'Show an override request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Override', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function show(string $id): OverrideResource
    {
        $override = BudgetOverride::query()->findOrFail($id);
        $this->authorize('view', $override);

        return new OverrideResource($override);
    }

    #[OA\Post(path: '/api/v1/budgeting/spend-control/overrides/{id}/approve', operationId: 'approveBudgetOverride', description: 'Approve an override, optionally for a lower amount and until a date. Reserved to owners, administrators and members granted budgeting.override.approve; the requester cannot approve their own request.', summary: 'Approve an override', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 200, description: 'Override approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function approve(OverrideDecisionRequest $request, string $id): OverrideResource
    {
        $override = BudgetOverride::query()->findOrFail($id);
        $this->authorize('decideOverride', $override);

        return new OverrideResource($this->overrides->approve($override, $this->userId(), $request->validated()));
    }

    #[OA\Post(path: '/api/v1/budgeting/spend-control/overrides/{id}/reject', operationId: 'rejectBudgetOverride', description: 'Reject an override; the comment with the reason is mandatory.', summary: 'Reject an override', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 200, description: 'Override rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function reject(OverrideDecisionRequest $request, string $id): OverrideResource
    {
        $override = BudgetOverride::query()->findOrFail($id);
        $this->authorize('decideOverride', $override);

        return new OverrideResource($this->overrides->reject($override, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Post(path: '/api/v1/budgeting/spend-control/overrides/{id}/cancel', operationId: 'cancelBudgetOverride', description: 'Withdraw a pending or approved override that was not used yet.', summary: 'Cancel an override', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 200, description: 'Override cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function cancel(OverrideDecisionRequest $request, string $id): OverrideResource
    {
        $override = BudgetOverride::query()->findOrFail($id);
        $this->authorize('update', $override);
        abort_unless($override->requested_by === $this->userId() || Gate::allows('decideOverride', $override), 403);

        return new OverrideResource($this->overrides->cancel($override, $this->userId(), $request->validated()['comment'] ?? null));
    }

    #[OA\Get(path: '/api/v1/budgeting/spend-control/overrides/{id}/history', operationId: 'listBudgetOverrideHistory', description: 'The audit trail of an override request: request, decision, consumption.', summary: 'Override history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'History', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function history(string $id): AnonymousResourceCollection
    {
        $override = BudgetOverride::query()->findOrFail($id);
        $this->authorize('view', $override);

        return AuditEventResource::collection(
            BudgetAuditEvent::query()->where('subject_type', BudgetAuditEvent::SubjectOverride)->where('subject_id', $override->id)->orderBy('created_at')->orderBy('id')->paginate($this->perPage()),
        );
    }
}
