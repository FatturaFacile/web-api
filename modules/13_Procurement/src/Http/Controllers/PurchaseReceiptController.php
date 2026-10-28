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
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Goods Receipts', description: 'Warehouse receipt notes, QA inspections, and receiving dock operations')]
final class PurchaseReceiptController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/goods-receipts',
        summary: 'List goods receipts',
        description: 'Retrieve paginated list of goods receipt notes with PO and inspection status filtering.',
        tags: ['Procurement Goods Receipts'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'purchase_order_id', in: 'query', description: 'Filter by PO UUID', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated goods receipts', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementGoodsReceipt')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementApiPaginationMeta'),
                ]
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/goods-receipt',
        summary: 'Process goods receipt note (GRN) against purchase order',
        description: 'Receives physical items against PO with lot tracking, warehouse location, and inspection.',
        tags: ['Procurement Goods Receipts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_delivery_note_ref', 'received_date', 'lines'],
                properties: [
                    new OA\Property(property: 'supplier_delivery_note_ref', type: 'string', example: 'DDT-2026-991'),
                    new OA\Property(property: 'received_date', type: 'string', format: 'date', example: '2026-10-28'),
                    new OA\Property(property: 'inspection_status', type: 'string', example: 'passed', enum: ['passed', 'failed', 'conditional']),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'purchase_order_line_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'quantity_received', type: 'number', example: 50),
                            new OA\Property(property: 'quantity_accepted', type: 'number', example: 50),
                            new OA\Property(property: 'quantity_rejected', type: 'number', example: 0),
                            new OA\Property(property: 'lot_number', type: 'string', example: 'LOT-2026-A1'),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Goods receipt processed', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementGoodsReceipt')),
            new OA\Response(response: 403, description: 'Segregation of duties violation or invalid PO state'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/procurement/goods-receipts/{id}',
        summary: 'Show goods receipt note details',
        description: 'Retrieve GRN details, received lots, inspection results, and receiver identity.',
        tags: ['Procurement Goods Receipts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Goods Receipt UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Goods receipt details', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementGoodsReceipt')),
            new OA\Response(response: 404, description: 'Goods receipt not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Show goods receipt note details', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $receipt = PurchaseReceipt::with(['purchaseOrder', 'receiver', 'lines'])->findOrFail($id);
        $this->authorize('view', $receipt);

        return response()->json(new PurchaseReceiptResource($receipt));
    }
}
