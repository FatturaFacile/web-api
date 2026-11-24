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
use Modules\CreditManagement\Http\Requests\ApproveLimitRequestRequest;
use Modules\CreditManagement\Http\Requests\LimitRequestRequest;
use Modules\CreditManagement\Http\Requests\RejectLimitRequestRequest;
use Modules\CreditManagement\Http\Resources\LimitRequestResource;
use Modules\CreditManagement\Models\CreditLimitRequest;
use Modules\CreditManagement\Services\LimitRequestService;
use OpenApi\Attributes as OA;

/**
 * Credit limit requests with approval.
 */
final class LimitRequestController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly LimitRequestService $requests) {}

    #[OA\Get(path: '/api/v1/credit-management/limit-requests', operationId: 'listCreditLimitRequests', description: 'Credit limit requests, latest first. Filters: customer_id, status.', summary: 'List credit limit requests', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List credit limit requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditLimitRequest'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditLimitRequest::class);
        $filters = $request->validate(['customer_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(CreditLimitRequest::Statuses)]]);
        $page = CreditLimitRequest::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['customer_id']), static fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($this->perPage());
        $this->withCustomerNames($page->getCollection());

        return LimitRequestResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/credit-management/limit-requests', operationId: 'storeCreditLimitRequest', description: 'Ask for a credit limit for a customer. Without requested_limit the automatic proposal of the latest score is requested. One request per customer is pending at a time.', summary: 'Request a credit limit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditLimitRequestRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Request a credit limit', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditLimitRequest')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function store(LimitRequestRequest $request): JsonResponse
    {
        $this->authorize('create', CreditLimitRequest::class);
        $limitRequest = $this->requests->create($this->tenantId(), $this->userId(), $request->validated());
        $this->withCustomerNames([$limitRequest]);

        return (new LimitRequestResource($limitRequest))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/credit-management/limit-requests/{id}', operationId: 'showCreditLimitRequest', description: 'Return a credit limit request.', summary: 'Show a credit limit request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Show a credit limit request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditLimitRequest')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function show(string $id): LimitRequestResource
    {
        $limitRequest = CreditLimitRequest::query()->findOrFail($id);
        $this->authorize('view', $limitRequest);
        $this->withCustomerNames([$limitRequest]);

        return new LimitRequestResource($limitRequest);
    }

    #[OA\Post(path: '/api/v1/credit-management/limit-requests/{id}/approve', operationId: 'approveCreditLimitRequest', description: 'Approve the request, at the limit asked or at approved_limit. Reserved to owners, administrators and members granted credit-management.approve; the requester cannot decide their own request; an approved limit above the proposal needs a decision note. The limit is stored where the sales credit check reads it.', summary: 'Approve a credit limit request', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditApproveLimitRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Approve a credit limit request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditLimitRequest')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function approve(ApproveLimitRequestRequest $request, string $id): LimitRequestResource
    {
        $limitRequest = CreditLimitRequest::query()->findOrFail($id);
        $this->authorize('approve', $limitRequest);
        $limitRequest = $this->requests->approve($limitRequest, (string) $this->userId(), $request->validated());
        $this->withCustomerNames([$limitRequest]);

        return new LimitRequestResource($limitRequest);
    }

    #[OA\Post(path: '/api/v1/credit-management/limit-requests/{id}/reject', operationId: 'rejectCreditLimitRequest', description: 'Reject a pending request with a note. Same approvers and same-user rule as the approval.', summary: 'Reject a credit limit request', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditRejectLimitRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Reject a credit limit request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditLimitRequest')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function reject(RejectLimitRequestRequest $request, string $id): LimitRequestResource
    {
        $limitRequest = CreditLimitRequest::query()->findOrFail($id);
        $this->authorize('approve', $limitRequest);
        $limitRequest = $this->requests->reject($limitRequest, (string) $this->userId(), $request->validated('decision_note'));
        $this->withCustomerNames([$limitRequest]);

        return new LimitRequestResource($limitRequest);
    }

    #[OA\Post(path: '/api/v1/credit-management/limit-requests/{id}/cancel', operationId: 'cancelCreditLimitRequest', description: 'Withdraw a pending request.', summary: 'Cancel a credit limit request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Cancel a credit limit request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditLimitRequest')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function cancel(string $id): LimitRequestResource
    {
        $limitRequest = CreditLimitRequest::query()->findOrFail($id);
        $this->authorize('update', $limitRequest);
        $limitRequest = $this->requests->cancel($limitRequest);
        $this->withCustomerNames([$limitRequest]);

        return new LimitRequestResource($limitRequest);
    }
}
