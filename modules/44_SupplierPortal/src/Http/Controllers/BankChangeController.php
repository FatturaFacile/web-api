<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\SupplierPortal\Http\Controllers\Concerns\InteractsWithSupplierPortal;
use Modules\SupplierPortal\Http\Requests\BankChangeDecisionRequest;
use Modules\SupplierPortal\Http\Requests\BankChangeSubmitRequest;
use Modules\SupplierPortal\Http\Resources\BankChangeResource;
use Modules\SupplierPortal\Models\BankChangeRequest;
use Modules\SupplierPortal\Services\BankChangeService;
use OpenApi\Attributes as OA;

/**
 * Bank detail change requests of the suppliers, applied only after a callback and the approval of two different users.
 */
final class BankChangeController extends Controller
{
    use InteractsWithSupplierPortal;

    public function __construct(private readonly BankChangeService $changes) {}

    #[OA\Get(path: '/api/v1/supplier-portal/bank-changes', operationId: 'listSupplierBankChanges', description: 'Paginated bank detail change requests, newest first. Filters: status, supplier_id.', summary: 'List bank change requests', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'applied', 'rejected', 'cancelled'])),
        new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(ref: '#/components/parameters/SupplierPortalPerPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpBankChange'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BankChangeRequest::class);
        $request->validate(['status' => ['nullable', Rule::in(BankChangeRequest::Statuses)]]);

        return BankChangeResource::collection(
            BankChangeRequest::query()
                ->with(['supplier', 'approvals'])
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('supplier_id'), static fn ($query) => $query->where('supplier_id', (string) $request->query('supplier_id')))
                ->orderByDesc('created_at')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/supplier-portal/bank-changes', operationId: 'storeSupplierBankChange', description: 'Enters a bank detail change request for a supplier (for example received by mail or phone). The IBAN is validated; only one request per supplier is open at a time. Nothing changes until it is verified by callback and approved twice.', summary: 'Enter a bank change request', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpBankChangeRequest')), tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 201, description: 'Request opened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpBankChange')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function store(BankChangeSubmitRequest $request): JsonResponse
    {
        $this->authorize('create', BankChangeRequest::class);
        $supplier = $this->supplier((string) $request->validated('supplier_id'));

        $change = $this->changes->request($supplier, $request->validated(), 'internal', $this->userId());

        return (new BankChangeResource($change->load(['supplier', 'approvals'])))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/supplier-portal/bank-changes/{id}', operationId: 'showSupplierBankChange', description: 'A bank change request with the callback verification and the decisions taken.', summary: 'Show a bank change request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpBankChange')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function show(string $id): BankChangeResource
    {
        $change = $this->find($id);
        $this->authorize('view', $change);

        return new BankChangeResource($change);
    }

    #[OA\Post(path: '/api/v1/supplier-portal/bank-changes/{id}/verify-callback', operationId: 'verifySupplierBankChangeCallback', description: 'Records that the change was confirmed by calling the supplier on a phone number already on file (never the one in the request). Required before any approval. Not allowed to the requester.', summary: 'Record the callback verification', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpBankChangeDecisionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Callback recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpBankChange')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function verifyCallback(BankChangeDecisionRequest $request, string $id): BankChangeResource
    {
        $change = $this->find($id);
        $this->authorize('decideBankChange', $change);

        return new BankChangeResource($this->changes->verifyCallback($change, (string) $request->validated('note'), (string) $this->userId())->load(['supplier', 'approvals']));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/bank-changes/{id}/approve', operationId: 'approveSupplierBankChange', description: 'Adds the approval of the user. Two different users (none of them the requester) must approve after the callback; with the second approval the IBAN of the supplier is changed. Owners, administrators, accountants or supplier_portal.bank_approve.', summary: 'Approve a bank change request', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SpBankChangeDecisionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Approval recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpBankChange')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function approve(BankChangeDecisionRequest $request, string $id): BankChangeResource
    {
        $change = $this->find($id);
        $this->authorize('decideBankChange', $change);

        return new BankChangeResource($this->changes->approve($change, (string) $this->userId(), $request->validated('comment'))->load(['supplier', 'approvals']));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/bank-changes/{id}/reject', operationId: 'rejectSupplierBankChange', description: 'One rejection with a reason ends the request.', summary: 'Reject a bank change request', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpBankChangeDecisionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Request rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpBankChange')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function reject(BankChangeDecisionRequest $request, string $id): BankChangeResource
    {
        $change = $this->find($id);
        $this->authorize('decideBankChange', $change);

        return new BankChangeResource($this->changes->reject($change, (string) $this->userId(), (string) $request->validated('reason'))->load(['supplier', 'approvals']));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/bank-changes/{id}/cancel', operationId: 'cancelSupplierBankChange', description: 'Withdraws a request that is still open.', summary: 'Cancel a bank change request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Request cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpBankChange')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function cancel(string $id): BankChangeResource
    {
        $change = $this->find($id);
        $this->authorize('update', $change);

        return new BankChangeResource($this->changes->cancel($change)->load(['supplier', 'approvals']));
    }

    private function find(string $id): BankChangeRequest
    {
        return BankChangeRequest::query()->with(['supplier', 'approvals'])->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
