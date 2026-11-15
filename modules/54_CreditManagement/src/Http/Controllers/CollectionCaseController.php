<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Http\Requests\AdvanceCaseRequest;
use Modules\CreditManagement\Http\Requests\AssignCaseRequest;
use Modules\CreditManagement\Http\Requests\CaseActionRequest;
use Modules\CreditManagement\Http\Requests\CloseCaseRequest;
use Modules\CreditManagement\Http\Requests\CollectionCaseRequest;
use Modules\CreditManagement\Http\Requests\CollectionCaseUpdateRequest;
use Modules\CreditManagement\Http\Resources\CaseActionResource;
use Modules\CreditManagement\Http\Resources\CollectionCaseResource;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Services\CollectionCaseService;
use OpenApi\Attributes as OA;

/**
 * Collection cases worked along a dunning strategy.
 */
final class CollectionCaseController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly CollectionCaseService $cases) {}

    #[OA\Get(path: '/api/v1/credit-management/cases', operationId: 'listCreditCollectionCases', description: 'Collection cases, latest first. Filters: status, customer_id, collector_id, priority.', summary: 'List collection cases', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List collection cases', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditCollectionCase'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditCollectionCase::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(CreditCollectionCase::Statuses)],
            'customer_id' => ['nullable', 'uuid'],
            'collector_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', Rule::in(CreditCollectionCase::Priorities)],
        ]);
        $page = CreditCollectionCase::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), static fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['collector_id']), static fn ($query) => $query->where('collector_id', $filters['collector_id']))
            ->when(isset($filters['priority']), static fn ($query) => $query->where('priority', $filters['priority']))
            ->orderByDesc('opened_on')
            ->orderByDesc('case_number')
            ->paginate($this->perPage());
        $this->withCustomerNames($page->getCollection());

        return CollectionCaseResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/credit-management/cases', operationId: 'storeCreditCollectionCase', description: 'Open a case on the overdue invoices of a customer (all of them, or the given invoice_ids). The default dunning strategy applies unless strategy_id is given. An invoice is in one open case at a time.', summary: 'Open a collection case', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditCollectionCaseRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Open a collection case', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionCase')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function store(CollectionCaseRequest $request): JsonResponse
    {
        $this->authorize('create', CreditCollectionCase::class);
        $case = $this->cases->open($this->tenantId(), $request->validated());
        $this->withCustomerNames([$case]);

        return (new CollectionCaseResource($case->load(['invoices', 'strategy.steps', 'actions', 'promises', 'disputes'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/credit-management/cases/{id}', operationId: 'showCreditCollectionCase', description: 'A case with its invoices, strategy steps, actions, promises and disputes.', summary: 'Show a collection case', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Show a collection case', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionCase')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function show(string $id): CollectionCaseResource
    {
        $case = CreditCollectionCase::query()->with(['invoices', 'strategy.steps', 'actions', 'promises', 'disputes'])->findOrFail($id);
        $this->authorize('view', $case);
        $this->withCustomerNames([$case]);

        return new CollectionCaseResource($case);
    }

    #[OA\Put(path: '/api/v1/credit-management/cases/{id}', operationId: 'updateCreditCollectionCase', description: 'Change priority, notes, collector or the next action date of an open case.', summary: 'Update a collection case', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditCollectionCaseUpdateRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Update a collection case', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionCase')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function update(CollectionCaseUpdateRequest $request, string $id): CollectionCaseResource
    {
        $case = CreditCollectionCase::query()->findOrFail($id);
        $this->authorize('update', $case);
        $case = $this->cases->update($case, $request->validated())->load(['invoices', 'strategy.steps', 'actions', 'promises', 'disputes']);
        $this->withCustomerNames([$case]);

        return new CollectionCaseResource($case);
    }

    #[OA\Post(path: '/api/v1/credit-management/cases/{id}/assign', operationId: 'assignCreditCollectionCase', description: 'Set the collector of an open case (null clears it).', summary: 'Assign a case to a collector', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditAssignCaseRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Assign a case to a collector', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionCase')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function assign(AssignCaseRequest $request, string $id): CollectionCaseResource
    {
        $case = CreditCollectionCase::query()->findOrFail($id);
        $this->authorize('update', $case);
        $case = $this->cases->assign($case, $request->validated('collector_id'))->load(['invoices', 'strategy.steps', 'actions', 'promises', 'disputes']);
        $this->withCustomerNames([$case]);

        return new CollectionCaseResource($case);
    }

    #[OA\Post(path: '/api/v1/credit-management/cases/{id}/advance', operationId: 'advanceCreditCollectionCase', description: 'Run the next step of the strategy: it must be due (the oldest invoice of the case is as many days overdue as the step asks) and the case must have no open dispute and no pending promise. The step is recorded as an action with its fee.', summary: 'Run the next dunning step', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditAdvanceCaseRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Run the next dunning step', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionCase')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function advance(AdvanceCaseRequest $request, string $id): CollectionCaseResource
    {
        $case = CreditCollectionCase::query()->findOrFail($id);
        $this->authorize('update', $case);
        $case = $this->cases->advance($case, $this->userId(), $request->validated('outcome'));
        $this->withCustomerNames([$case]);

        return new CollectionCaseResource($case);
    }

    #[OA\Post(path: '/api/v1/credit-management/cases/{id}/actions', operationId: 'storeCreditCollectionAction', description: 'Record a call, email, letter, visit, legal action or note done on an open case.', summary: 'Record an action on a case', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditCaseActionRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 201, description: 'Record an action on a case', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionAction')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function storeAction(CaseActionRequest $request, string $id): JsonResponse
    {
        $case = CreditCollectionCase::query()->findOrFail($id);
        $this->authorize('update', $case);

        return (new CaseActionResource($this->cases->addAction($case, $this->userId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/credit-management/cases/{id}/refresh', operationId: 'refreshCreditCollectionCase', description: 'Re-read what is still owed on the invoices of the case; a case with nothing outstanding is resolved.', summary: 'Refresh the amounts of a case', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Refresh the amounts of a case', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionCase')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function refresh(string $id): CollectionCaseResource
    {
        $case = CreditCollectionCase::query()->findOrFail($id);
        $this->authorize('update', $case);
        $case = $this->cases->refresh($case)->load(['invoices', 'strategy.steps', 'actions', 'promises', 'disputes']);
        $this->withCustomerNames([$case]);

        return new CollectionCaseResource($case);
    }

    #[OA\Post(path: '/api/v1/credit-management/cases/{id}/close', operationId: 'closeCreditCollectionCase', description: 'Close as resolved (only when nothing is outstanding) or written off (reserved to owners, administrators and members granted credit-management.approve). A case with an open dispute cannot be closed.', summary: 'Close a collection case', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditCloseCaseRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Close a collection case', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditCollectionCase')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function close(CloseCaseRequest $request, string $id): CollectionCaseResource
    {
        $case = CreditCollectionCase::query()->findOrFail($id);
        $this->authorize($request->validated('outcome') === CreditCollectionCase::WrittenOff ? 'approve' : 'update', $case);
        $case = $this->cases->close($case, $request->validated('outcome'), $request->validated('note'));
        $this->withCustomerNames([$case]);

        return new CollectionCaseResource($case);
    }
}
