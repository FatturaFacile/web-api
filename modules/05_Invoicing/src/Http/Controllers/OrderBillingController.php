<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Invoicing\Http\Requests\IssueDeferredInvoiceRequest;
use Modules\Invoicing\Http\Requests\IssueDownPaymentRequest;
use Modules\Invoicing\Http\Requests\RunDeferredInvoicingRequest;
use Modules\Invoicing\Http\Resources\SalesInvoiceResource;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Services\OrderBillingService;
use OpenApi\Attributes as OA;

/**
 * Down-payment invoices of sales orders and deferred invoices of delivery notes (single and as a billing run). Every
 * invoice is issued at once: numbered from the gapless sequence and posted to the ledger.
 */
final class OrderBillingController extends Controller
{
    public function __construct(private readonly OrderBillingService $billing) {}

    /**
     * Issues a down-payment invoice (TD02) for a percentage or an amount of the net total of a sales order.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/orders/{orderId}/down-payment-invoices',
        operationId: 'issueInvoicingOrderDownPayment',
        description: 'Issues a down-payment invoice (TD02) for a percentage of the net total of the order or for an amount, split over the VAT rates of the order in proportion to their net. The down payments of an order never exceed its net total; the final invoice of the order deducts what is left of them line by line, at their VAT rate.',
        summary: 'Issue down-payment invoice of a sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(allOf: [
            new OA\Schema(properties: [
                new OA\Property(property: 'percentage', type: 'number', maximum: 100, minimum: 0, exclusiveMinimum: true, nullable: true),
                new OA\Property(property: 'amount', description: 'Net amount, two decimals at most; instead of percentage.', type: 'number', nullable: true),
            ], type: 'object'),
            new OA\Schema(ref: '#/components/schemas/InvoicingBillingOverrides'),
        ])),
        tags: ['Invoicing Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/InvoicingBillingOrderId')],
        responses: [
            new OA\Response(response: 201, ref: '#/components/responses/InvoicingBillingIssuedInvoice'),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/InvoicingBillingNotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function downPayment(string $orderId, IssueDownPaymentRequest $request): JsonResponse
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $percentage = $request->validated('percentage');
        $amount = $request->validated('amount');

        $invoice = $this->billing->issueDownPayment(
            $order,
            $percentage === null ? null : (string) $percentage,
            $amount === null ? null : (string) $amount,
            $request->safe()->except(['percentage', 'amount']),
            (string) $request->user()?->id
        );

        return response()->json(new SalesInvoiceResource($invoice->load('items')), 201);
    }

    /**
     * Issues one deferred invoice for several dispatched delivery notes of the same customer.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/invoices/deferred',
        operationId: 'issueInvoicingDeferredInvoice',
        description: 'Issues one deferred invoice (fattura differita; TD24 for an Italian company) for dispatched delivery notes of the same customer and currency that were not billed yet. Each invoice line keeps its delivery note line: the order lines are billed and the FatturaPA lists the transport documents (DatiDDT). The delivery notes become invoiced.',
        summary: 'Issue deferred invoice of delivery notes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(allOf: [
            new OA\Schema(required: ['delivery_note_ids'], properties: [
                new OA\Property(property: 'delivery_note_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: 200, minItems: 1),
            ], type: 'object'),
            new OA\Schema(ref: '#/components/schemas/InvoicingBillingOverrides'),
        ])),
        tags: ['Invoicing Billing'],
        responses: [
            new OA\Response(response: 201, ref: '#/components/responses/InvoicingBillingIssuedInvoice'),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function deferred(IssueDeferredInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->billing->issueDeferredInvoice(
            array_values($request->validated('delivery_note_ids')),
            $request->safe()->except(['delivery_note_ids']),
            (string) $request->user()?->id
        );

        return response()->json(new SalesInvoiceResource($invoice->load('items')), 201);
    }

    /**
     * Runs the deferred billing: one invoice per customer and currency for the delivery notes up to a date.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/invoices/deferred-run',
        operationId: 'runInvoicingDeferredBilling',
        description: 'Issues one deferred invoice per customer and currency for every dispatched delivery note dated on or before up_to_date and not billed yet (optionally of one customer). Each invoice is issued in its own transaction: a customer that cannot be billed is reported in errors and does not stop the others.',
        summary: 'Run deferred billing',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['up_to_date'], properties: [
            new OA\Property(property: 'up_to_date', type: 'string', format: 'date'),
            new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'date', description: 'Date of the invoices, today on the company clock when omitted.', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        ], type: 'object')),
        tags: ['Invoicing Billing'],
        responses: [
            new OA\Response(response: 200, description: 'Run completed', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingBillingRunResult')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function deferredRun(RunDeferredInvoicingRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $result = $this->billing->runDeferredInvoicing(
            (string) $tenantContext->getTenantId(),
            (string) $request->validated('up_to_date'),
            $request->validated('contact_id'),
            $request->safe()->only(['date', 'due_date']),
            (string) $request->user()?->id
        );

        return response()->json([
            'invoices' => SalesInvoiceResource::collection(collect($result['invoices'])->map(static fn (SalesInvoice $invoice): SalesInvoice => $invoice->load('items'))),
            'errors' => $result['errors'],
        ]);
    }
}
