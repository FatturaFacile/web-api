<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\ApproveThreeWayMatchVarianceRequest;
use Modules\Procurement\Http\Requests\InspectGoodsReceiptRequest;
use Modules\Procurement\Http\Requests\ProcurementDecisionRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Http\Resources\PurchaseReceiptResource;
use Modules\Procurement\Http\Resources\PurchaseReturnResource;
use Modules\Procurement\Http\Resources\ThreeWayMatchLogResource;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReturn;
use Modules\Procurement\Models\ThreeWayMatchLog;
use Modules\Procurement\Services\ProcurementService;
use Modules\Procurement\Services\PurchaseOrderLifecycleService;
use Modules\Procurement\Services\QualityInspectionService;
use Modules\Procurement\Services\ThreeWayMatchingService;
use OpenApi\Attributes as OA;

/**
 * Operations on existing procurement documents: sending an approved order, the quality inspection of a receipt in
 * quarantine, the credit note of a return, and the approval of a three-way match variance.
 */
#[OA\Tag(name: 'Procurement Operations', description: 'Sending orders, quality inspection, return credit notes and match variances')]
final class ProcurementOperationsController extends Controller
{
    use ProcurementControllerHelpers;

    /**
     * Marks an approved order as sent to the supplier.
     */
    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/send',
        operationId: 'procurementOrdersSend',
        description: 'Marks an approved order as sent to the supplier.',
        summary: 'Send purchase order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Operations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Order sent', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function sendOrder(string $id, PurchaseOrderLifecycleService $lifecycle): JsonResponse
    {
        return response()->json(new PurchaseOrderResource($lifecycle->markSent(PurchaseOrder::query()->findOrFail($id))));
    }

    /**
     * Records the quality inspection of a receipt.
     */
    #[OA\Post(
        path: '/api/v1/procurement/goods-receipts/{id}/inspection',
        operationId: 'procurementReceiptsInspect',
        description: 'For a receipt received into quarantine: the released quantity of each line moves to the receiving bin through the inventory module, the rest stays in quarantine until returned to the supplier. Each line is inspected once.',
        summary: 'Record quality inspection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['results'], properties: [
            new OA\Property(property: 'results', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'purchase_receipt_line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity_released', type: 'string'),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
            ])),
        ])),
        tags: ['Procurement Operations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Receipt inspected', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementGoodsReceipt')),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function inspect(InspectGoodsReceiptRequest $request, string $id, QualityInspectionService $inspections): JsonResponse
    {
        /** @var list<array{purchase_receipt_line_id: string, quantity_released: string, notes?: ?string}> $results */
        $results = $request->validated('results');
        $receipt = $inspections->inspect(PurchaseReceipt::query()->findOrFail($id), $results, (string) $this->actorId($request));

        return response()->json(new PurchaseReceiptResource($receipt->load('lines')));
    }

    /**
     * Links the credit note received for a return.
     */
    #[OA\Post(
        path: '/api/v1/procurement/returns/{id}/credit-note',
        operationId: 'procurementReturnsCreditNote',
        description: 'Links the supplier credit note (a received document of type TD04 of the same supplier, or only its reference) to a return waiting for credit; the return becomes credited.',
        summary: 'Record return credit note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'credit_note_reference', type: 'string', nullable: true),
        ])),
        tags: ['Procurement Operations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Return credited', content: new OA\JsonContent(type: 'object')),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function creditNote(ProcurementDecisionRequest $request, string $id, ProcurementService $procurement): JsonResponse
    {
        $data = $request->validated();
        $return = $procurement->recordReturnCreditNote(PurchaseReturn::query()->findOrFail($id), $data['passive_invoice_id'] ?? null, $data['credit_note_reference'] ?? null);

        return response()->json(new PurchaseReturnResource($return->load('lines')));
    }

    /**
     * Approves the variance of a failed three-way match.
     */
    #[OA\Post(
        path: '/api/v1/procurement/three-way-match/{id}/approve-variance',
        operationId: 'procurementThreeWayMatchApproveVariance',
        description: 'Approves the variance of a match beyond tolerance with a reason: the supplier invoice is released for payment.',
        summary: 'Approve three-way match variance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000, example: 'Price increase agreed with the supplier by phone', description: 'Why the variance beyond tolerance is accepted')])),
        tags: ['Procurement Operations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Variance approved', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementThreeWayMatchLog')),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function approveVariance(ApproveThreeWayMatchVarianceRequest $request, string $id, ThreeWayMatchingService $matching): JsonResponse
    {
        $reason = (string) ($request->validated('reason') ?? '');

        if ($reason === '') {
            throw new ProcurementRuleViolation('Give the reason of the approval.', 'reason');
        }

        $user = $request->user();
        $log = $matching->approveVariance(ThreeWayMatchLog::query()->findOrFail($id), $user instanceof User ? $user : null, $reason);

        return response()->json(new ThreeWayMatchLogResource($log));
    }
}
