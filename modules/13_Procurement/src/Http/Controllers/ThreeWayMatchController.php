<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\ExecuteThreeWayMatchAction;
use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;
use Modules\Procurement\Http\Requests\ExecuteThreeWayMatchRequest;
use Modules\Procurement\Http\Resources\ThreeWayMatchLogResource;
use Modules\Procurement\Models\ThreeWayMatchLog;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Three-Way Matching', description: 'Reconciliation of Purchase Orders, Goods Receipts, and Vendor Invoices')]
final class ThreeWayMatchController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/three-way-match',
        summary: 'List three-way matching audit logs and variance reports',
        description: 'Retrieve matching logs with status filter (matched, price_variance, quantity_variance).',
        tags: ['Procurement Three-Way Matching'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'match_status', in: 'query', description: 'Filter by matching result', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated matching logs', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementThreeWayMatchLog')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementApiPaginationMeta'),
                ]
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List three-way matching audit logs and variance reports', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $query = ThreeWayMatchLog::with(['purchaseOrder', 'receipt'])
            ->orderBy('evaluated_at', 'desc');

        if ($request->filled('match_status')) {
            $query->where('match_status', $request->input('match_status'));
        }

        $logs = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(ThreeWayMatchLogResource::collection($logs)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/procurement/three-way-match/execute',
        summary: 'Execute algorithmic three-way match on PO, GRN and Inbound Invoice',
        description: 'Performs line-level matching between PO lines, GRN accepted quantities, and vendor invoice lines.',
        tags: ['Procurement Three-Way Matching'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['purchase_order_id', 'purchase_receipt_id', 'passive_invoice_id', 'invoice_total_amount'],
                properties: [
                    new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'purchase_receipt_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'invoice_total_amount', type: 'number', example: 12500.00),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Matching executed', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementThreeWayMatchLog')),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Execute algorithmic three-way match on PO, GRN and Inbound Invoice', tags: ['Procurement'])]
    public function execute(ExecuteThreeWayMatchRequest $request, ExecuteThreeWayMatchAction $action): JsonResponse
    {
        $dto = new ExecuteThreeWayMatchDTO(
            purchaseOrderId: $request->string('purchase_order_id')->value(),
            purchaseReceiptId: $request->string('purchase_receipt_id')->value(),
            passiveInvoiceId: $request->string('passive_invoice_id')->value(),
            invoiceTotalAmount: (float) $request->input('invoice_total_amount'),
            approvedByUserId: (string) auth()->id()
        );

        $log = $action->execute($dto);

        return response()->json(new ThreeWayMatchLogResource($log), 201);
    }
}
