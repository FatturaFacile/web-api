<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\DownPayments\Http\Controllers\Concerns\InteractsWithDownPayments;
use Modules\DownPayments\Http\Requests\AdvanceCreditNoteRequest;
use Modules\DownPayments\Http\Requests\ConfirmPaymentRequest;
use Modules\DownPayments\Http\Requests\FinalSettlementRequest;
use Modules\DownPayments\Services\AdvanceCreditNoteService;
use Modules\DownPayments\Services\AdvanceEmissionService;
use Modules\DownPayments\Services\ChainReadService;
use Modules\DownPayments\Services\FinalSettlementService;
use OpenApi\Attributes as OA;

/**
 * The closing documents of a chain: the conversion of a paid pro-forma into the TD02, the final settlement invoice with the
 * multi-rate deduction of the advances, and the credit note (TD04) of an advance with the reopening of the chain.
 */
final class SettlementController extends Controller
{
    use ApiResponse;
    use InteractsWithDownPayments;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly AdvanceEmissionService $advances,
        private readonly FinalSettlementService $settlement,
        private readonly AdvanceCreditNoteService $creditNotes,
        private readonly ChainReadService $reader,
    ) {}

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/proforma/{linkId}/confirm-payment', operationId: 'confirmProformaPayment', summary: 'Manual twin of the Treasury reconciliation: converts a paid pro-forma advance request into the official TD02 dated on the payment day', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'linkId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The chain'), new OA\Response(response: 422, description: 'Rule refused')])]
    public function confirmProforma(ConfirmPaymentRequest $request, string $id, string $linkId): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('emit', $chain);
        $data = $request->validated();
        $this->advances->convertProforma($chain, $this->link($chain, $linkId), (string) $data['paid_at'], $this->userId(), $data['reference'] ?? null, $data['payment_method'] ?? null);

        return $this->successResponse($this->reader->detail($chain->refresh()), $this->message('proforma_converted'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/preview-final-settlement', operationId: 'previewFinalSettlement', summary: 'Preview of the final settlement invoice: the multi-VAT deductions, the DatiFattureCollegate references and the closing entry of the customer advances', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The preview')])]
    public function preview(string $id): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('view', $chain);

        return $this->successResponse($this->settlement->preview($chain), $this->message('previewed'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/generate-final-settlement', operationId: 'generateFinalSettlement', summary: 'One-click final settlement invoice (TD01 or TD24) with the negative deduction lines per VAT rate and advance, the XML references and the ledger closure', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'The chain'), new OA\Response(response: 422, description: 'Rule refused')])]
    public function generate(FinalSettlementRequest $request, string $id): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('settle', $chain);
        $invoice = $this->settlement->generate($chain, $request->validated(), $this->userId());

        return $this->createdResponse(['invoice_id' => (string) $invoice->id, 'invoice_number' => (string) $invoice->invoice_number, 'chain' => $this->reader->detail($chain->refresh())], $this->message('settled'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/links/{linkId}/credit-note/preview', operationId: 'previewAdvanceCreditNote', summary: 'What a credit note against an advance would take back, and the residual of the order it would reopen', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'linkId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The preview')])]
    public function previewCreditNote(AdvanceCreditNoteRequest $request, string $id, string $linkId): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('view', $chain);

        return $this->successResponse($this->creditNotes->preview($chain, $this->link($chain, $linkId), $request->validated()), $this->message('previewed'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/links/{linkId}/credit-note', operationId: 'issueAdvanceCreditNote', summary: 'Partial or total credit note (TD04) against an advance invoice, with the reopening of the residual of the order', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'linkId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'The chain'), new OA\Response(response: 422, description: 'Rule refused')])]
    public function creditNote(AdvanceCreditNoteRequest $request, string $id, string $linkId): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('creditNote', $chain);
        $credit = $this->creditNotes->issue($chain, $this->link($chain, $linkId), $request->validated(), $this->userId());

        return $this->createdResponse(['credit_note_id' => $credit->document_id, 'credit_note_number' => $credit->document_number, 'chain' => $this->reader->detail($chain->refresh())], $this->message('credit_note_issued'));
    }
}
