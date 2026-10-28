<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\ProcessGoodsReceiptAction;
use Modules\Procurement\DTOs\ProcessGoodsReceiptDTO;
use Modules\Procurement\Http\Requests\ProcessGoodsReceiptRequest;
use Modules\Procurement\Http\Resources\PurchaseReceiptResource;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;

final class PurchaseReceiptController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List goods receipts', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseReceipt::class);

        $query = PurchaseReceipt::with(['purchaseOrder', 'receiver', 'lines'])
            ->orderBy('received_date', 'desc');

        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', $request->input('purchase_order_id'));
        }

        $receipts = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(PurchaseReceiptResource::collection($receipts)->response()->getData(true));
    }

    #[EnterpriseEndpoint(summary: 'Process goods receipt note (GRN) against purchase order', tags: ['Procurement'])]
    public function store(string $orderId, ProcessGoodsReceiptRequest $request, ProcessGoodsReceiptAction $action): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($orderId);
        $this->authorize('receive', $order);

        $dto = new ProcessGoodsReceiptDTO(
            purchaseOrderId: $order->id,
            supplierDeliveryNoteRef: $request->string('supplier_delivery_note_ref')->value(),
            receivedDate: $request->string('received_date')->value(),
            receivedByUserId: (string) auth()->id(),
            lines: (array) $request->input('lines'),
            receiptNumber: $request->input('receipt_number'),
            warehouseId: $request->input('warehouse_id'),
            inspectionStatus: $request->string('inspection_status', 'passed')->value(),
            notes: $request->input('notes')
        );

        $receipt = $action->execute($dto);

        return response()->json(new PurchaseReceiptResource($receipt), 201);
    }

    #[EnterpriseEndpoint(summary: 'Show goods receipt note details', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $receipt = PurchaseReceipt::with(['purchaseOrder', 'receiver', 'lines'])->findOrFail($id);
        $this->authorize('view', $receipt);

        return response()->json(new PurchaseReceiptResource($receipt));
    }
}
