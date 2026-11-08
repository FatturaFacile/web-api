<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Invoicing\Http\Requests\ReceivablesAgingRequest;
use Modules\Invoicing\Http\Requests\SalesRegisterRequest;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\InvoiceInstallmentService;
use Modules\Invoicing\Services\SalesReportService;
use OpenApi\Attributes as OA;

/**
 * Read-only views of issued sales documents: the payment schedule of a document, the sales register of a period and
 * the receivables aging. They read posted documents and ledger entries only.
 */
final class SalesReportController extends Controller
{
    public function __construct(private readonly SalesReportService $reports) {}

    /**
     * The payment schedule (scadenze) of a sales document.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/invoices/{id}/installments',
        operationId: 'getInvoicingInvoiceInstallments',
        description: 'Returns the installments of a sales document of the active company, fixed at emission from its payment term, with what is paid and still open on each (payments applied to the earliest due dates first). A document without a payment term has a single installment: its due date for its amount due.',
        summary: 'Get payment schedule of a sales document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Reports'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/InvoicingBillingInvoiceId')],
        responses: [
            new OA\Response(response: 200, description: 'Payment schedule', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingInstallmentSchedule')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 404, ref: '#/components/responses/InvoicingBillingNotFound'),
        ]
    )]
    public function installments(string $id, InvoiceInstallmentService $installments): JsonResponse
    {
        $invoice = SalesInvoice::query()->findOrFail($id);

        return response()->json([
            'invoice_id' => (string) $invoice->id,
            'currency' => (string) $invoice->currency,
            'installments' => $installments->scheduleOf($invoice),
        ]);
    }

    /**
     * The sales register of a period.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/reports/sales-register',
        operationId: 'getInvoicingSalesRegister',
        description: 'Lists the issued sales documents of a period (at most one year), in number order, credit notes with negative amounts, with the VAT per rate taken from their posted journal entries and the totals per currency. Drafts and proformas are not listed. The statutory VAT registers are served by the General Ledger module.',
        summary: 'Sales register',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'document_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'TD01')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales register', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesRegister')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function salesRegister(SalesRegisterRequest $request, TenantContext $tenantContext): JsonResponse
    {
        return response()->json($this->reports->salesRegister(
            (string) $tenantContext->getTenantId(),
            (string) $request->validated('from'),
            (string) $request->validated('to'),
            $request->validated('document_type')
        ));
    }

    /**
     * The receivables aging at a date.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/reports/receivables-aging',
        operationId: 'getInvoicingReceivablesAging',
        description: 'What customers still owe on issued documents at a date (today on the company clock by default), per customer and currency, in the buckets current, 1-30, 31-60, 61-90 and over 90 days past due of each installment.',
        summary: 'Receivables aging',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Reports'],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Receivables aging', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingReceivablesAging')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function receivablesAging(ReceivablesAgingRequest $request, TenantContext $tenantContext, TenantClock $clock): JsonResponse
    {
        return response()->json($this->reports->receivablesAging(
            (string) $tenantContext->getTenantId(),
            (string) ($request->validated('as_of') ?? $clock->today($tenantContext->getTenant())->toDateString()),
            $request->validated('customer_id')
        ));
    }
}
