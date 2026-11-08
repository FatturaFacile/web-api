<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Http\Requests\IssueDebitNoteRequest;
use Modules\Invoicing\Http\Resources\SalesInvoiceResource;
use Modules\Invoicing\Models\SalesInvoice;
use OpenApi\Attributes as OA;

/**
 * Debit notes: documents that raise what a customer owes on an issued invoice (price adjustments, charges billed
 * after the invoice). They are issued and posted at once, like any invoice, and reference the invoice they adjust.
 */
final class DebitNoteController extends Controller
{
    /**
     * Issues a debit note for an issued invoice.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/debit-notes',
        operationId: 'createInvoicingDebitNote',
        description: 'Issues a debit note (TD05) for an issued invoice of the active company: same customer, currency, exchange rate, jurisdiction and VAT regime (split payment, reverse charge) as the invoice, numbered in the invoice series and posted to the ledger as a receivable. The FatturaPA references the invoice in DatiFattureCollegate. Credit notes, proformas and drafts cannot be adjusted with a debit note.',
        summary: 'Issue debit note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['original_invoice_id', 'items'], properties: [
            new OA\Property(property: 'original_invoice_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(required: ['description', 'quantity', 'unit_price', 'tax_rate'], properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'description', type: 'string', maxLength: 1000),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'unit_price', type: 'number'),
                new OA\Property(property: 'tax_rate', type: 'number'),
                new OA\Property(property: 'discount_percentage', type: 'number', nullable: true),
            ], type: 'object'), minItems: 1),
        ], type: 'object')),
        tags: ['Invoicing Credit Notes'],
        responses: [
            new OA\Response(response: 201, description: 'Debit note issued', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function store(IssueDebitNoteRequest $request, InvoicingServiceInterface $invoicing): JsonResponse
    {
        $validated = $request->validated();
        $original = SalesInvoice::query()->findOrFail((string) $validated['original_invoice_id']);
        $items = $validated['items'];
        unset($validated['items'], $validated['original_invoice_id']);

        $debitNote = $invoicing->issueDebitNote($original, $validated, $items, (string) $request->user()?->id);

        return response()->json(new SalesInvoiceResource($debitNote->load('items')), 201);
    }
}
