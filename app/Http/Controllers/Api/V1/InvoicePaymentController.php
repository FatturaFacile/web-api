<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConvertToInvoice;
use App\Actions\Sales\RecordPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StorePaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class InvoicePaymentController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(
        private readonly RecordPayment $recordPayment,
        private readonly ConvertToInvoice $toInvoice,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}/payments',
        operationId: 'getInvoicePayments',
        description: 'List recorded payments and receipts for an invoice.',
        summary: 'List invoice payments',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales', 'Payments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Invoice UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payments list retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('view', $invoice);

        return $this->successResponse(
            PaymentResource::collection($invoice->payments()->orderBy('paid_at')->get()),
            __('sales.messages.payments_list')
        );
    }

    #[OA\Post(
        path: '/api/v1/sales/invoices/{id}/payments',
        operationId: 'recordInvoicePayment',
        description: 'Record an incoming payment against an invoice.',
        summary: 'Record invoice payment',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales', 'Payments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Invoice UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'paid_at'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 120.50),
                    new OA\Property(property: 'paid_at', type: 'string', format: 'date', example: '2026-10-25'),
                    new OA\Property(property: 'payment_method', type: 'string', example: 'MP05', description: 'Italian FatturaPA payment method code (e.g. MP05 for bonifico)'),
                    new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true, example: 'TRX-987654'),
                    new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Payment recorded successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StorePaymentRequest $request, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('recordPayment', $invoice);

        $payment = $this->recordPayment->execute($invoice, $request->validated());

        return $this->successResponse(new PaymentResource($payment), __('sales.messages.payment_recorded'), Response::HTTP_CREATED);
    }

    /**
     * Convert a proforma invoice into a standard numbered invoice.
     */
    #[OA\Post(
        path: '/api/v1/sales/invoices/{id}/convert-proforma',
        operationId: 'convertProformaInvoice',
        description: 'Convert a proforma invoice into an official numbered sales invoice.',
        summary: 'Convert proforma invoice',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Invoice UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Proforma converted successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function convertProforma(string $id): JsonResponse
    {
        $invoice = Invoice::with('items')->findOrFail($id);
        $this->authorize('update', $invoice);

        $invoice = $this->toInvoice->fromProforma($invoice);

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.proforma_converted'), Response::HTTP_CREATED);
    }
}
