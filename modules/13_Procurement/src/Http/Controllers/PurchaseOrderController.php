<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\CreatePurchaseOrderAction;
use Modules\Procurement\Actions\SubmitPurchaseOrderForApprovalAction;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Http\Requests\StorePurchaseOrderRequest;
use Modules\Procurement\Http\Requests\UpdatePurchaseOrderRequest;
use Modules\Procurement\Http\Requests\WorkflowActionRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Models\PurchaseOrder;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Purchase Orders', description: 'Purchase orders lifecycle, approvals, receiving, and commitments')]
final class PurchaseOrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/purchase-orders',
        summary: 'List purchase orders',
        description: 'Retrieve paginated list of purchase orders with status, vendor, and date filtering.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by PO status', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'supplier_id', in: 'query', description: 'Filter by supplier UUID', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by PO number or notes', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort field (-created_at, po_number)', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated purchase orders', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementPurchaseOrder')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementApiPaginationMeta'),
                ]
            )),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List purchase orders', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::with(['supplier', 'lines', 'receipts']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->value().'%';
            $query->where(function ($q) use ($search): void {
                $q->where('po_number', 'like', $search)
                    ->orWhere('notes', 'like', $search);
            });
        }

        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        if (in_array($column, ['created_at', 'po_number', 'total_amount', 'order_date', 'expected_delivery_date'], true)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $orders = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(PurchaseOrderResource::collection($orders)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders',
        summary: 'Create purchase order',
        description: 'Creates a purchase order in draft state with line items.',
        tags: ['Procurement Purchase Orders'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_id', 'order_date', 'expected_delivery_date', 'lines'],
                properties: [
                    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'order_date', type: 'string', format: 'date', example: '2026-10-28'),
                    new OA\Property(property: 'expected_delivery_date', type: 'string', format: 'date', example: '2026-11-15'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'description', type: 'string', example: 'Industrial Bearings ISO-9001'),
                            new OA\Property(property: 'quantity_ordered', type: 'number', example: 100),
                            new OA\Property(property: 'unit_price', type: 'number', example: 12.50),
                            new OA\Property(property: 'uom', type: 'string', example: 'PCS'),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Purchase order created', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create purchase order', tags: ['Procurement'])]
    public function store(StorePurchaseOrderRequest $request, CreatePurchaseOrderAction $action): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $dto = new CreatePurchaseOrderDTO(
            supplierId: $request->string('supplier_id')->value(),
            orderDate: $request->string('order_date')->value(),
            expectedDeliveryDate: $request->string('expected_delivery_date')->value(),
            lines: (array) $request->input('lines'),
            poNumber: $request->input('po_number'),
            requisitionId: $request->input('purchase_requisition_id'),
            contractId: $request->input('supplier_contract_id'),
            projectId: $request->input('project_id'),
            costCenterId: $request->input('cost_center_id'),
            departmentId: $request->input('department_id'),
            branchId: $request->input('branch_id'),
            orderType: $request->string('order_type', 'standard')->value(),
            currency: $request->string('currency', 'EUR')->value(),
            exchangeRate: (float) $request->input('exchange_rate', 1.0),
            paymentTermsCode: $request->input('payment_terms_code'),
            incoterms: $request->input('incoterms'),
            shippingAddress: (array) $request->input('shipping_address', []),
            notes: $request->input('notes'),
            creatorUserId: (string) auth()->id()
        );

        $order = $action->execute($dto);

        return response()->json(new PurchaseOrderResource($order), 201);
    }

    #[OA\Get(
        path: '/api/v1/procurement/purchase-orders/{id}',
        summary: 'Show purchase order details and line items',
        description: 'Retrieve full order details, line items, receipts, and three-way match records.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Purchase order details', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(response: 404, description: 'Order not found', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Show purchase order details and line items', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $order = PurchaseOrder::with(['supplier', 'lines', 'receipts', 'matchLogs'])->findOrFail($id);
        $this->authorize('view', $order);

        return response()->json(new PurchaseOrderResource($order));
    }

    #[OA\Put(
        path: '/api/v1/procurement/purchase-orders/{id}',
        summary: 'Update purchase order',
        description: 'Updates a draft purchase order.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order updated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(response: 403, description: 'Order is locked or not draft', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update purchase order', tags: ['Procurement'])]
    public function update(UpdatePurchaseOrderRequest $request, string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('update', $order);

        $validated = $request->validated();
        $order->update(collect($validated)->except('lines')->toArray());

        return response()->json(new PurchaseOrderResource($order->fresh(['supplier', 'lines', 'receipts'])));
    }

    #[OA\Delete(
        path: '/api/v1/procurement/purchase-orders/{id}',
        summary: 'Delete draft purchase order',
        description: 'Soft-deletes a purchase order if still in draft status.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order deleted successfully'),
            new OA\Response(response: 403, description: 'Order is locked or processed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Delete draft purchase order', tags: ['Procurement'])]
    public function destroy(string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('delete', $order);

        $order->delete();

        return response()->json(['message' => 'Purchase order deleted successfully']);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/submit-approval',
        summary: 'Submit purchase order for approval via Workflow Engine V2',
        description: 'Initiates approval workflow for purchase order authorization.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order submitted for approval', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(response: 403, description: 'Order is locked or not draft'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Submit purchase order for approval via Workflow Engine V2', tags: ['Procurement'])]
    public function submitApproval(string $id, SubmitPurchaseOrderForApprovalAction $action): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('submitApproval', $order);

        $submitted = $action->execute($order);

        return response()->json(new PurchaseOrderResource($submitted));
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/approve',
        summary: 'Approve purchase order',
        description: 'Approves a submitted purchase order subject to segregation of duties.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order approved', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(response: 403, description: 'Segregation of duties violation'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Approve purchase order', tags: ['Procurement'])]
    public function approve(WorkflowActionRequest $request, string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('approve', $order);

        $order->update([
            'status' => 'approved',
            'is_locked' => false,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        return response()->json(new PurchaseOrderResource($order->fresh(['supplier', 'lines', 'receipts'])));
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/reject',
        summary: 'Reject purchase order',
        description: 'Rejects a purchase order submitted for approval and unlocks it.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order rejected', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Reject purchase order', tags: ['Procurement'])]
    public function reject(WorkflowActionRequest $request, string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('reject', $order);

        $order->update([
            'status' => 'rejected',
            'is_locked' => false,
        ]);

        return response()->json(new PurchaseOrderResource($order->fresh(['supplier', 'lines', 'receipts'])));
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/cancel',
        summary: 'Cancel purchase order and release encumbered commitments',
        description: 'Cancels order and dispatches cancellation events to reverse commitments.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order cancelled', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Cancel purchase order and release encumbered commitments', tags: ['Procurement'])]
    public function cancel(string $id, Request $request, ProcurementServiceInterface $service): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('cancel', $order);

        $reason = $request->string('reason', 'Cancelled by authorized user')->value();
        $service->cancelPurchaseOrder($order, $reason);

        $order->refresh();

        return response()->json(new PurchaseOrderResource($order));
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/close',
        summary: 'Close purchase order',
        description: 'Closes a completed purchase order.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order closed', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Close purchase order', tags: ['Procurement'])]
    public function close(string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('close', $order);

        $order->update(['status' => 'closed']);

        return response()->json(new PurchaseOrderResource($order->fresh(['supplier', 'lines', 'receipts'])));
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/reopen',
        summary: 'Reopen purchase order',
        description: 'Reopens a previously closed purchase order.',
        tags: ['Procurement Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order reopened', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Reopen purchase order', tags: ['Procurement'])]
    public function reopen(string $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('reopen', $order);

        $order->update(['status' => 'approved']);

        return response()->json(new PurchaseOrderResource($order->fresh(['supplier', 'lines', 'receipts'])));
    }
}
