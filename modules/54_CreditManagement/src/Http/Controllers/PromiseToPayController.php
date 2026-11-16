<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Http\Requests\PromiseToPayRequest;
use Modules\CreditManagement\Http\Requests\ResolvePromiseRequest;
use Modules\CreditManagement\Http\Resources\PromiseToPayResource;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditPromiseToPay;
use Modules\CreditManagement\Services\PromiseToPayService;
use OpenApi\Attributes as OA;

/**
 * Promises to pay recorded on collection cases.
 */
final class PromiseToPayController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly PromiseToPayService $promises) {}

    #[OA\Get(path: '/api/v1/credit-management/promises', operationId: 'listCreditPromisesToPay', description: 'Promises to pay by promised date. Filters: case_id, status.', summary: 'List promises to pay', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List promises to pay', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditPromiseToPay'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditPromiseToPay::class);
        $filters = $request->validate(['case_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(CreditPromiseToPay::Statuses)]]);

        return PromiseToPayResource::collection(
            CreditPromiseToPay::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['case_id']), static fn ($query) => $query->where('case_id', $filters['case_id']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderBy('promised_on')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/credit-management/promises', operationId: 'storeCreditPromiseToPay', description: 'Record a promise of the customer on an open case: for today or later, at most the outstanding amount, one pending promise per case. The case becomes promised.', summary: 'Record a promise to pay', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditPromiseToPayRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Record a promise to pay', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditPromiseToPay')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function store(PromiseToPayRequest $request): JsonResponse
    {
        $this->authorize('create', CreditPromiseToPay::class);
        $data = $request->validated();
        $case = CreditCollectionCase::query()->findOrFail($data['case_id']);
        $this->authorize('update', $case);

        return (new PromiseToPayResource($this->promises->create($case, $this->userId(), $data)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/credit-management/promises/{id}/resolve', operationId: 'resolveCreditPromiseToPay', description: 'Close a pending promise as kept (paid_amount of at least the promised amount), broken or cancelled. The case re-reads its receivables and goes back to dunning, or is resolved when nothing is left.', summary: 'Resolve a promise to pay', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditResolvePromiseRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Resolve a promise to pay', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditPromiseToPay')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function resolve(ResolvePromiseRequest $request, string $id): PromiseToPayResource
    {
        $promise = CreditPromiseToPay::query()->findOrFail($id);
        $this->authorize('update', $promise);

        return new PromiseToPayResource($this->promises->resolve($promise, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/credit-management/promises/mark-broken', operationId: 'markCreditPromisesBroken', description: 'Mark as broken every pending promise whose promised date has passed. Returns the number of promises marked.', summary: 'Mark overdue promises as broken', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'Mark overdue promises as broken', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditMarkedPromises')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function markBroken(): JsonResponse
    {
        $this->authorize('update', new CreditPromiseToPay(['tenant_id' => $this->tenantId()]));

        return response()->json(['data' => ['marked' => $this->promises->markBroken($this->tenantId(), Carbon::today())]]);
    }
}
