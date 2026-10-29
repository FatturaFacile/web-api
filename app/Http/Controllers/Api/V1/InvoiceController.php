<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceService;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\SwissQrBill\QrBillGeneratorService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly FatturaPaBuilderService $fatturaPaBuilder,
        private readonly QrBillGeneratorService $qrBillGenerator
    ) {}

    /**
     * List paginated invoices with optional status/jurisdiction filters.
     */
    #[OA\Get(
        path: '/api/v1/sales/invoices',
        operationId: 'getInvoices',
        description: 'List paginated invoices with optional status, jurisdiction, and search keyword filters.',
        summary: 'List invoices',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales'],
        parameters: [
            new OA\Parameter(
                name: 'payment_status',
                in: 'query',
                required: false,
                description: 'Filter by payment status',
                schema: new OA\Schema(type: 'string', enum: ['unpaid', 'partially_paid', 'paid', 'overdue'])
            ),
            new OA\Parameter(
                name: 'jurisdiction',
                in: 'query',
                required: false,
                description: 'Filter by tax jurisdiction',
                schema: new OA\Schema(type: 'string', enum: ['IT', 'CH'])
            ),
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Search invoice number or contact name',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Number of results per page (5-100)',
                schema: new OA\Schema(type: 'integer', default: 15)
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                description: 'Page number for pagination',
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoices retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Invoices retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/InvoiceResource')
                        ),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::with(['contact', 'items', 'sdiMetadata', 'qrBillData'])
            ->latest('date');

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->query('payment_status'));
        }

        if ($request->filled('jurisdiction')) {
            $query->where('jurisdiction', strtoupper((string) $request->query('jurisdiction')));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('contact', function ($cq) use ($search): void {
                        $cq->where('company_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $invoices = $query->paginate($perPage);

        return $this->successResponse(
            data: InvoiceResource::collection($invoices),
            message: 'Invoices retrieved successfully',
            meta: [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ]
        );
    }

    /**
     * Store a newly created invoice with validated items and tax computations.
     */
    #[OA\Post(
        path: '/api/v1/sales/invoices',
        operationId: 'createInvoice',
        description: 'Store a newly created invoice with validated items and tax computations.',
        summary: 'Create new invoice',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['contact_id', 'invoice_type', 'invoice_number', 'date', 'currency', 'jurisdiction', 'items'],
                properties: [
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', example: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'),
                    new OA\Property(property: 'invoice_type', type: 'string', enum: ['standard', 'sdi_electronic', 'ch_qr_bill', 'credit_note', 'proforma'], example: 'standard'),
                    new OA\Property(property: 'invoice_number', type: 'string', maxLength: 50, example: 'INV-2026-0001'),
                    new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-10-25'),
                    new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true, example: '2026-11-24'),
                    new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
                    new OA\Property(property: 'exchange_rate', type: 'number', format: 'float', nullable: true, example: 1.0),
                    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH'], example: 'CH'),
                    new OA\Property(property: 'split_payment', type: 'boolean', nullable: true, example: false),
                    new OA\Property(property: 'reverse_charge', type: 'boolean', nullable: true, example: false),
                    new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true, example: 'Payment within 30 days.'),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            required: ['description', 'quantity', 'unit_price', 'tax_rate'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
                                new OA\Property(property: 'description', type: 'string', example: 'Consulting services'),
                                new OA\Property(property: 'quantity', type: 'number', format: 'float', example: 10),
                                new OA\Property(property: 'unit_price', type: 'number', format: 'float', example: 120.00),
                                new OA\Property(property: 'tax_rate', type: 'number', format: 'float', example: 8.1),
                                new OA\Property(property: 'discount_percentage', type: 'number', format: 'float', nullable: true, example: 0),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Invoice created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Invoice created successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/InvoiceResource'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $invoice = $this->invoiceService->createInvoice($request->validated());

        return $this->successResponse(
            data: new InvoiceResource($invoice),
            message: 'Invoice created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Display the specified invoice with details.
     */
    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}',
        operationId: 'getInvoiceById',
        description: 'Display the specified invoice with contact details, items, SDI status and QR-bill information.',
        summary: 'Get invoice details',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Unique UUID of the invoice',
                schema: new OA\Schema(type: 'string', format: 'uuid', example: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice details retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Invoice details retrieved successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/InvoiceResource'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Invoice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::with(['contact.addresses', 'items', 'sdiMetadata', 'qrBillData'])->findOrFail($id);

        $this->authorize('view', $invoice);

        return $this->successResponse(
            data: new InvoiceResource($invoice),
            message: 'Invoice details retrieved successfully'
        );
    }

    /**
     * Generate official Italian SDI electronic invoice XML (FatturaPA v1.2.2).
     */
    #[OA\Post(
        path: '/api/v1/sales/invoices/{id}/generate-sdi-xml',
        operationId: 'generateSdiXml',
        description: 'Generate official Italian SDI electronic invoice XML (FatturaPA v1.2.2) and store SDI metadata.',
        summary: 'Generate SDI XML',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'SDI'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Unique UUID of the invoice',
                schema: new OA\Schema(type: 'string', format: 'uuid', example: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'FatturaPA electronic XML invoice generated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Invoice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function generateSdiXml(string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenant', 'contact.addresses', 'items'])->findOrFail($id);
        $this->authorize('sendSdi', $invoice);

        $xml = $this->fatturaPaBuilder->buildFatturaPaXml($invoice);
        $fileName = sprintf(
            'IT%s_%s.xml',
            preg_replace('/\D/', '', $invoice->tenant->vat_number ?: '99999999999'),
            substr(preg_replace('/[^A-Za-z0-9]/', '', $invoice->invoice_number), 0, 5)
        );

        $metadata = $invoice->sdiMetadata()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'transmission_format' => ($invoice->contact?->sdi_code && strlen(trim($invoice->contact->sdi_code)) === 6) ? 'FPA12' : 'FPR12',
                'sdi_status' => 'draft',
                'file_name' => $fileName,
                'xml_content' => $xml,
            ]
        );

        return $this->successResponse(
            data: [
                'invoice_id' => $invoice->id,
                'file_name' => $fileName,
                'sdi_status' => $metadata->sdi_status,
                'xml_preview' => $xml,
            ],
            message: 'FatturaPA electronic XML invoice generated successfully'
        );
    }

    /**
     * Generate Swiss QR-Bill data structure, SCOR reference, and SVG payment slip.
     *
     * @deprecated Produces a placeholder slip. Use GET /api/v1/sales/invoices/{id}/qr-bill for the real Swiss QR code.
     */
    #[OA\Post(
        path: '/api/v1/sales/invoices/{id}/generate-qr-bill',
        operationId: 'generateQrBill',
        description: 'Generate Swiss QR-Bill data structure, SCOR reference, and SVG payment slip.',
        summary: 'Generate Swiss QR-Bill',
        deprecated: true,
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Swiss QR'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Unique UUID of the invoice',
                schema: new OA\Schema(type: 'string', format: 'uuid', example: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Swiss QR-Bill payment slip generated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Invoice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function generateQrBill(string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenant', 'contact.addresses'])->findOrFail($id);
        $this->authorize('generateQrBill', $invoice);

        $qrData = $this->qrBillGenerator->generateForInvoice($invoice);

        $invoice->qrBillData()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'qr_iban' => $qrData['qr_iban'],
                'scor_reference' => $qrData['scor_reference'],
                'creditor_info' => $qrData['creditor_info'],
                'debtor_info' => $qrData['debtor_info'],
                'qr_bill_svg' => $qrData['svg'],
            ]
        );

        // Update rounded total on invoice if currency is CHF
        if ($invoice->currency === 'CHF') {
            $invoice->total_gross = $qrData['amount'];
            $invoice->rounding_amount = $qrData['rounding_amount'];
            $invoice->save();
        }

        return $this->successResponse(
            data: [
                'invoice_id' => $invoice->id,
                'qr_iban' => $qrData['qr_iban'],
                'scor_reference' => $qrData['scor_reference'],
                'scor_formatted' => $qrData['scor_formatted'],
                'amount' => $qrData['amount'],
                'rounding_amount' => $qrData['rounding_amount'],
                'currency' => $qrData['currency'],
                'raw_payload' => $qrData['payload'],
                'svg_preview' => $qrData['svg'],
            ],
            message: 'Swiss QR-Bill payment slip generated successfully'
        );
    }

    /**
     * Download the Swiss QR-Bill SVG payment slip file directly.
     *
     * @deprecated Serves a placeholder slip. Use GET /api/v1/sales/invoices/{id}/qr-bill or the invoice PDF instead.
     */
    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}/download-qr-bill',
        operationId: 'downloadQrBill',
        description: 'Download the Swiss QR-Bill SVG payment slip file directly.',
        summary: 'Download Swiss QR-Bill SVG',
        deprecated: true,
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Swiss QR'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Unique UUID of the invoice',
                schema: new OA\Schema(type: 'string', format: 'uuid', example: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'SVG payment slip file stream',
                content: new OA\MediaType(mediaType: 'image/svg+xml')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Invoice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function downloadQrBill(string $id): HttpResponse
    {
        $invoice = Invoice::with(['qrBillData', 'tenant', 'contact'])->findOrFail($id);
        $this->authorize('download', $invoice);

        if (! $invoice->qrBillData || empty($invoice->qrBillData->qr_bill_svg)) {
            $qrData = $this->qrBillGenerator->generateForInvoice($invoice);
            $svg = $qrData['svg'];
        } else {
            $svg = $invoice->qrBillData->qr_bill_svg;
        }

        $fileName = "qr-bill-{$invoice->invoice_number}.svg";

        return response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('update', $invoice);

        $invoice->update($request->only(['notes', 'due_date']));

        return $this->successResponse(new InvoiceResource($invoice->fresh()), 'Invoice updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('delete', $invoice);

        $invoice->delete();

        return $this->successResponse(null, 'Invoice deleted successfully');
    }
}
