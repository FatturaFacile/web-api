<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers\Receivables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Http\Requests\Receivables\AllocateReceivablesRequest;
use Modules\Invoicing\Http\Requests\Receivables\DisputeReceivableRequest;
use Modules\Invoicing\Http\Requests\Receivables\ReceivablesQueryRequest;
use Modules\Invoicing\Http\Resources\ReceivableAllocationResource;
use Modules\Invoicing\Http\Resources\ReceivableItemResource;
use Modules\Invoicing\Models\ReceivableItem;
use Modules\Invoicing\Services\Receivables\AgingBucketPolicy;
use Modules\Invoicing\Services\Receivables\ReceivableItemService;
use Modules\Invoicing\Services\Receivables\ReceivableReconciliationService;
use OpenApi\Attributes as OA;

final class ReceivableItemController extends ReceivablesController
{
    #[OA\Get(path: '/api/v1/invoicing/receivables/items', operationId: 'listInvoicingReceivableItems', summary: 'List the open items of the customers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], responses: [new OA\Response(response: 200, description: 'Paginated open items'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function index(ReceivablesQueryRequest $request, ReceivableItemService $items): JsonResponse
    {
        $today = $this->today($request);
        $filters = $request->validated();
        $items->syncTenant($this->tenantId(), $filters['customer_id'] ?? null);

        $page = $items->paginate($this->tenantId(), $filters, (int) ($filters['per_page'] ?? 50), AgingBucketPolicy::forTenant($this->tenantId()), $today);

        return response()->json(ReceivableItemResource::collection($page)->response()->getData(true));
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/items/{id}', operationId: 'showInvoicingReceivableItem', summary: 'Show an open item with its allocations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The open item'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function show(string $id, Request $request): JsonResponse
    {
        $this->today($request);
        $item = ReceivableItem::query()->with(['allocations' => static fn ($query) => $query->orderBy('value_date')->orderBy('created_at')])->where('tenant_id', $this->tenantId())->findOrFail($id);

        return response()->json(['data' => new ReceivableItemResource($item)]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/ledger', operationId: 'getInvoicingReceivablesCustomerLedger', summary: 'Customer ledger (mastrino clienti) with running balance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ledger movements and closing balance'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function ledger(string $customerId, ReceivablesQueryRequest $request, ReceivableItemService $items): JsonResponse
    {
        $this->ensureCustomer($customerId);
        $items->syncTenant($this->tenantId(), $customerId);

        return response()->json(['data' => $items->ledger($this->tenantId(), $customerId, $request->validated())]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/unreconciled-payments', operationId: 'listInvoicingReceivablesUnreconciledPayments', summary: 'Payments on account not yet allocated', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Unallocated payments'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function unreconciledPayments(string $customerId, ReceivableReconciliationService $reconciliation): JsonResponse
    {
        $this->ensureCustomer($customerId);

        return response()->json(['data' => $reconciliation->unreconciledPayments($this->tenantId(), $customerId)]);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/allocations', operationId: 'allocateInvoicingReceivables', summary: 'Reconcile open items (payment, credit note, write-off, discount, debit adjustment)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 201, description: 'Allocations created'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function allocate(AllocateReceivablesRequest $request, ReceivableReconciliationService $reconciliation): JsonResponse
    {
        $this->authorizeManage();
        $this->today($request);
        $created = $reconciliation->allocateMany($request->validated('allocations'), $this->userId($request));

        $items = ReceivableItem::query()->whereKey(collect($created)->pluck('receivable_item_id')->unique()->all())->get();

        return response()->json([
            'data' => [
                'allocations' => ReceivableAllocationResource::collection(collect($created)),
                'items' => ReceivableItemResource::collection($items),
            ],
        ], 201);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/allocations/{id}/reverse', operationId: 'reverseInvoicingReceivableAllocation', summary: 'Reverse an allocation made by mistake', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Allocation reversed'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Cannot be reversed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function reverse(string $id, Request $request, ReceivableReconciliationService $reconciliation): JsonResponse
    {
        $this->authorizeManage();
        $allocation = $reconciliation->reverse($id, $this->userId($request));

        return response()->json(['data' => new ReceivableAllocationResource($allocation)]);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/items/{id}/dispute', operationId: 'disputeInvoicingReceivableItem', summary: 'Mark an open item as disputed or clear the dispute', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 200, description: 'The updated item'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function dispute(string $id, DisputeReceivableRequest $request, ReceivableReconciliationService $reconciliation): JsonResponse
    {
        $this->authorizeManage();
        $this->today($request);
        ReceivableItem::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $item = $reconciliation->setDisputed($id, (bool) $request->validated('disputed'), $request->validated('reason'));

        return response()->json(['data' => new ReceivableItemResource($item)]);
    }
}
