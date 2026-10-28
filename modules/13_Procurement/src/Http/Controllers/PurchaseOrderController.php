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
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Models\PurchaseOrder;

final class PurchaseOrderController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List purchase orders', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::with(['supplier', 'lines', 'receipts'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        $orders = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(PurchaseOrderResource::collection($orders)->response()->getData(true));
    }

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

    #[EnterpriseEndpoint(summary: 'Show purchase order details and line items', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $order = PurchaseOrder::with(['supplier', 'lines', 'receipts', 'matchLogs'])->findOrFail($id);
        $this->authorize('view', $order);

        return response()->json(new PurchaseOrderResource($order));
    }

    #[EnterpriseEndpoint(summary: 'Submit purchase order for approval via Workflow Engine V2', tags: ['Procurement'])]
    public function submitApproval(string $id, SubmitPurchaseOrderForApprovalAction $action): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->authorize('update', $order);

        $submitted = $action->execute($order);

        return response()->json(new PurchaseOrderResource($submitted));
    }

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
}
