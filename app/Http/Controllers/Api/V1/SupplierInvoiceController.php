<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchases\ReceivePassiveInvoice;
use App\Actions\Sdi\CreateSelfInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StoreSelfInvoiceRequest;
use App\Http\Requests\Purchases\UploadPassiveInvoiceRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\PassiveInvoice;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Supplier Invoices', description: 'Supplier invoices, passive SDI XML receiving, and payment status')]
class SupplierInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReceivePassiveInvoice $receive,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * List received supplier documents. Filters: `supplier_id`, `document_type`, `payment_status`.
     *
     * @response array{success: bool, message: string, data: list<array{id: string, tenant_id: string|null, supplier_id: string, invoice_number: string, date: string, due_date: string|null, total_amount: string, currency: string, sdi_lot_id: string|null, is_reconciled: bool, qr_raw_payload: string|null, created_at: string|null, updated_at: string|null, document_type: string, total_net: string, total_tax: string, payment_status: string, document_hash: string|null, sdi_file_name: string|null, total_paid: string, gl_journal_entry_id: string|null, exchange_rate: string|null, withholding_tax_amount: string, withholdings: array<int, array<string, mixed>>|null, declaration_of_intent_id: string|null, cost_account_id: string|null, cost_account_source: string|null, approval_status: string, cost_center_id: string|null, approval_workflow_instance_id: string|null, approval_submitted_by: string|null, approval_decided_at: string|null, stamp_duty_amount: string, purchase_order_id: string|null, match_status: string|null, approval_status_before_block: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    #[OA\Get(
        path: '/api/v1/purchases/supplier-invoices',
        summary: 'List supplier invoices',
        description: 'Get paginated list of received supplier documents with optional filters',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'supplier_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'document_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'payment_status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier invoices retrieved successfully: every stored column of the document (decimals as strings, dates as ISO 8601 date-times, the raw XML hidden), newest document date first', content: new OA\JsonContent(ref: '#/components/schemas/PurchasesSupplierInvoiceListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $invoices = PassiveInvoice::query()
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->query('supplier_id')))
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->query('document_type')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->query('payment_status')))
            ->latest('date')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 15))));

        return $this->successResponse(
            data: CatalogResource::collection($invoices),
            message: __('purchases.messages.supplier_invoices'),
            meta: ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/purchases/supplier-invoices/{id}',
        summary: 'Get supplier invoice',
        description: 'Retrieve details for a single supplier invoice',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier invoice details retrieved'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PassiveInvoice::findOrFail($id)), __('purchases.messages.supplier_invoice'));
    }

    /**
     * Upload a FatturaPA XML received from SDI and register its documents.
     */
    #[OA\Post(
        path: '/api/v1/purchases/passive-invoices/receive-xml',
        summary: 'Receive passive SDI XML',
        description: 'Upload a FatturaPA XML received from SDI and register documents',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Passive XML parsed and registered'),
            new OA\Response(response: 422, description: 'Invalid XML or unprocessable document'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function receiveXml(UploadPassiveInvoiceRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            $results = $this->receive->execute((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse(
                title: __('purchases.invalid_fatturapa_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            array_map(fn (array $result): array => [
                'duplicate' => $result['duplicate'],
                'invoice' => (new CatalogResource($result['invoice']))->resolve($request),
            ], $results),
            __('purchases.messages.invoices_received'),
            Response::HTTP_CREATED
        );
    }

    /**
     * Issue the integration or self-invoice of a received invoice.
     */
    #[OA\Post(
        path: '/api/v1/purchases/passive-invoices/{id}/self-invoice',
        operationId: 'createSelfInvoiceFromPassiveInvoice',
        summary: 'Create a self-invoice from a received invoice',
        description: 'Issues a TD16-TD23, TD28 or TD29 document from a received supplier invoice: the supplier is the cedente, the tenant the cessionario, and the received invoice the DatiFattureCollegate reference. Nothing is collected on it, and only one document of each type can exist per received invoice.',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['document_type', 'date', 'tax_rate'],
                properties: [
                    new OA\Property(property: 'document_type', type: 'string', enum: ['TD16', 'TD17', 'TD18', 'TD19', 'TD20', 'TD21', 'TD22', 'TD23', 'TD28', 'TD29']),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'tax_rate', description: 'VAT rate the tenant accounts for. Zero requires vat_nature.', type: 'number'),
                    new OA\Property(property: 'vat_nature', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Self-invoice issued'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 422, description: 'Invalid type, inconsistent document, or already issued'),
        ]
    )]
    public function selfInvoice(StoreSelfInvoiceRequest $request, CreateSelfInvoice $action, string $id): JsonResponse
    {
        $source = PassiveInvoice::findOrFail($id);
        $invoice = $action->execute($source, $request->validated());

        return $this->successResponse(new InvoiceResource($invoice), __('sdi.messages.self_invoice_created'), Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/purchases/supplier-invoices/{id}/mark-paid',
        summary: 'Mark supplier invoice as paid',
        description: 'Update payment status of a supplier invoice to paid',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier invoice marked as paid'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 422, description: 'Invoice cannot be marked paid'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function markPaid(string $id): JsonResponse
    {
        $invoice = PassiveInvoice::findOrFail($id);

        if ($invoice->document_type === 'TD04') {
            throw ValidationException::withMessages(['invoice' => __('purchases.credit_note_not_payable')]);
        }

        if ($invoice->payment_status === 'paid') {
            throw ValidationException::withMessages(['invoice' => __('purchases.already_paid')]);
        }

        if ($invoice->isPaymentBlocked()) {
            throw ValidationException::withMessages(['invoice' => "Invoice {$invoice->invoice_number} is not approved for payment."]);
        }

        $invoice->update(['payment_status' => 'paid']);
        $this->events->record($invoice, 'marked_paid');

        return $this->successResponse(new CatalogResource($invoice), __('purchases.messages.marked_paid'));
    }
}
