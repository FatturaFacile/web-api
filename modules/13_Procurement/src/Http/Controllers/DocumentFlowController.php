<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Services\DocumentFlowService;
use OpenApi\Attributes as OA;

/**
 * Procure-to-pay document flow (traceability) of any purchasing document.
 */
#[OA\Tag(name: 'Procurement Document Flow', description: 'Linked upstream and downstream documents of the procure-to-pay chain')]
final class DocumentFlowController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(private readonly DocumentFlowService $flow) {}

    /**
     * Document flow of a document.
     */
    #[OA\Get(
        path: '/api/v1/procurement/document-flow/{type}/{id}',
        operationId: 'procurementDocumentFlow',
        description: 'Requisition, tender, framework agreement, purchase order, goods receipts, supplier invoices with their 3-way match status, returns and credit notes linked to the document, with ordered, received, invoiced, returned and remaining quantities.',
        summary: 'Procure-to-pay document flow',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Document Flow'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['requisition', 'rfq', 'purchase_order', 'goods_receipt', 'supplier_invoice', 'purchase_return'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Document flow', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(response: 404, description: 'Document not found'),
        ]
    )]
    public function show(string $type, string $id): JsonResponse
    {
        return response()->json(['data' => $this->flow->flow($this->tenantId(), $type, $id)]);
    }
}
