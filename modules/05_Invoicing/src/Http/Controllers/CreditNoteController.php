<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Invoicing\Actions\IssueCreditNoteAction;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Http\Requests\ApplyCreditNoteRequest;
use Modules\Invoicing\Http\Requests\IssueCreditNoteRequest;
use Modules\Invoicing\Http\Resources\SalesInvoiceResource;
use Modules\Invoicing\Models\SalesInvoice;
use OpenApi\Attributes as OA;

final class CreditNoteController extends Controller
{
    /**
     * Creates a new credit note in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/credit-notes',
        operationId: 'createInvoicingCreditNote',
        description: 'Creates a new credit note in the active tenant.',
        summary: 'Create credit note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Credit Notes'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingIssueCreditNoteRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Credit note created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(
        IssueCreditNoteRequest $request,
        IssueCreditNoteAction $action
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        $originalId = $validated['original_invoice_id'];
        unset($validated['items']);

        $originalInvoice = SalesInvoice::findOrFail($originalId);
        $userId = (string) ($request->user()?->id ?? 'system');
        $validated['created_by'] = $userId;

        $creditNote = $action->execute($originalInvoice, $validated, $items);

        return response()->json(new SalesInvoiceResource($creditNote), 201);
    }

    /**
     * Applies a credit note to a target sales invoice.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/credit-notes/{creditNoteId}/apply',
        operationId: 'applyInvoicingCreditNote',
        description: 'Applies a credit note to a target sales invoice.',
        summary: 'Apply credit note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Credit Notes'],
        parameters: [
            new OA\Parameter(name: 'creditNoteId', in: 'path', required: true, description: 'Identifier of the credit note', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_invoice_id', 'amount'], properties: [new OA\Property(property: 'target_invoice_id', type: 'string', format: 'uuid'), new OA\Property(property: 'amount', type: 'number', minimum: 0.01, description: 'Amount to apply, at most two decimals; it cannot exceed what is left on the credit note nor the outstanding amount of the invoice.')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'application_id', type: 'string', format: 'uuid'), new OA\Property(property: 'applied_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'), new OA\Property(property: 'target_invoice_status')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function apply(
        string $creditNoteId,
        ApplyCreditNoteRequest $request,
        InvoicingServiceInterface $service
    ): JsonResponse {
        $creditNote = SalesInvoice::findOrFail($creditNoteId);
        $targetInvoice = SalesInvoice::findOrFail((string) $request->validated('target_invoice_id'));
        $userId = (string) ($request->user()?->id ?? 'system');

        $application = $service->applyCreditNote($creditNote, $targetInvoice, $request->amount(), $userId);

        return response()->json([
            'message' => 'Credit note applied successfully',
            'application_id' => $application->id,
            'applied_amount' => $application->applied_amount,
            'target_invoice_status' => $targetInvoice->fresh()->payment_status,
        ]);
    }
}
