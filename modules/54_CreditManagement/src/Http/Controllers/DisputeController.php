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
use Modules\CreditManagement\Http\Requests\DisputeRequest;
use Modules\CreditManagement\Http\Requests\ResolveDisputeRequest;
use Modules\CreditManagement\Http\Resources\DisputeResource;
use Modules\CreditManagement\Models\CreditDispute;
use Modules\CreditManagement\Services\DisputeService;
use OpenApi\Attributes as OA;

/**
 * Disputes raised by customers on invoices.
 */
final class DisputeController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly DisputeService $disputes) {}

    #[OA\Get(path: '/api/v1/credit-management/disputes', operationId: 'listCreditDisputes', description: 'Disputes, latest first. Filters: status, customer_id, case_id.', summary: 'List disputes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List disputes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditDispute'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditDispute::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(CreditDispute::Statuses)], 'customer_id' => ['nullable', 'uuid'], 'case_id' => ['nullable', 'uuid']]);
        $page = CreditDispute::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), static fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['case_id']), static fn ($query) => $query->where('case_id', $filters['case_id']))
            ->orderByDesc('opened_on')
            ->orderByDesc('created_at')
            ->paginate($this->perPage());
        $this->withCustomerNames($page->getCollection());

        return DisputeResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/credit-management/disputes', operationId: 'storeCreditDispute', description: 'Raise a dispute on an open invoice of a customer, for at most the amount still due. A dispute on an invoice under collection joins that case and makes it disputed.', summary: 'Raise a dispute', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditDisputeRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Raise a dispute', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDispute')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function store(DisputeRequest $request): JsonResponse
    {
        $this->authorize('create', CreditDispute::class);
        $dispute = $this->disputes->create($this->tenantId(), $request->validated());
        $this->withCustomerNames([$dispute]);

        return (new DisputeResource($dispute))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/credit-management/disputes/{id}', operationId: 'showCreditDispute', description: 'Return a dispute.', summary: 'Show a dispute', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Show a dispute', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDispute')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function show(string $id): DisputeResource
    {
        $dispute = CreditDispute::query()->findOrFail($id);
        $this->authorize('view', $dispute);
        $this->withCustomerNames([$dispute]);

        return new DisputeResource($dispute);
    }

    #[OA\Post(path: '/api/v1/credit-management/disputes/{id}/review', operationId: 'reviewCreditDispute', description: 'Move an open dispute to under review.', summary: 'Start reviewing a dispute', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Start reviewing a dispute', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDispute')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function review(string $id): DisputeResource
    {
        $dispute = CreditDispute::query()->findOrFail($id);
        $this->authorize('update', $dispute);
        $dispute = $this->disputes->startReview($dispute);
        $this->withCustomerNames([$dispute]);

        return new DisputeResource($dispute);
    }

    #[OA\Post(path: '/api/v1/credit-management/disputes/{id}/resolve', operationId: 'resolveCreditDispute', description: 'Decide the dispute as upheld (credited_amount required, never above the disputed amount) or rejected. The case of the dispute re-reads its receivables and leaves the disputed status when no other dispute is open.', summary: 'Decide a dispute', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditResolveDisputeRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Decide a dispute', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDispute')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function resolve(ResolveDisputeRequest $request, string $id): DisputeResource
    {
        $dispute = CreditDispute::query()->findOrFail($id);
        $this->authorize('update', $dispute);
        $dispute = $this->disputes->resolve($dispute, $request->validated());
        $this->withCustomerNames([$dispute]);

        return new DisputeResource($dispute);
    }
}
