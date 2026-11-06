<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\Treasury\Actions\ExportSupplierPaymentsAction;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PurchasesController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly QrBillGeneratorService $qrBillGenerator,
        private readonly ExportSupplierPaymentsAction $paymentFiles
    ) {}

    #[OA\Get(
        path: '/api/v1/purchases/requests',
        operationId: 'getPurchaseRequests',
        description: 'List paginated internal purchase requests.',
        summary: 'List purchase requests',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Purchasing'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Purchase requests retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function requests(): JsonResponse
    {
        $requests = PurchaseRequest::with('user')->latest('requested_date')->paginate(25);

        return $this->successResponse(data: $requests, message: 'Purchase requests retrieved');
    }

    #[OA\Get(
        path: '/api/v1/purchases/orders',
        operationId: 'getPurchaseOrdersLegacy',
        description: 'List paginated purchase orders with suppliers and line items.',
        summary: 'List purchase orders',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Purchasing'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Purchase orders retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function orders(): JsonResponse
    {
        $orders = PurchaseOrder::with(['supplier', 'items'])->latest('date')->paginate(25);

        return $this->successResponse(data: $orders, message: 'Purchase orders retrieved');
    }

    #[OA\Get(
        path: '/api/v1/purchases/passive-invoices',
        operationId: 'getPassiveInvoices',
        description: 'List paginated accounts payable vendor invoices.',
        summary: 'List passive invoices',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Purchasing'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Passive invoices retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function passiveInvoices(): JsonResponse
    {
        $invoices = PassiveInvoice::with('supplier')->latest('date')->paginate(25);

        return $this->successResponse(data: $invoices, message: 'Passive invoices retrieved');
    }

    /**
     * Parse and import Italian FatturaPA XML into passive_invoices.
     *
     * @deprecated Legacy stub that inserts without parsing. Use POST /api/v1/purchases/passive-invoices/receive-xml instead.
     */
    #[OA\Post(
        path: '/api/v1/purchases/passive-invoices/import-sdi',
        operationId: 'importSdiPassiveInvoice',
        description: 'Legacy import endpoint for Italian FatturaPA XML files into passive invoices.',
        summary: 'Import SDI XML (deprecated)',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Purchasing'],
        responses: [
            new OA\Response(response: 201, description: 'Passive invoice imported successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 400, description: 'Invalid XML file', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
        deprecated: true
    )]
    public function importSdi(Request $request): JsonResponse
    {
        $request->validate([
            'xml_file' => ['required', 'file', 'mimes:xml,txt', 'max:10240'],
        ]);

        $content = file_get_contents($request->file('xml_file')->getRealPath());
        $dom = new DOMDocument;
        $loaded = @$dom->loadXML($content);

        if (! $loaded) {
            return $this->problemDetailsResponse(
                title: 'Invalid XML File',
                detail: 'The uploaded file is not a valid XML document.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Extract key FatturaPA fields
        $docNumNodes = $dom->getElementsByTagName('Numero');
        $invoiceNumber = $docNumNodes->length > 0 ? $docNumNodes->item(0)->nodeValue : 'REC-'.time();

        $docDateNodes = $dom->getElementsByTagName('Data');
        $invoiceDate = $docDateNodes->length > 0 ? $docDateNodes->item(0)->nodeValue : now()->toDateString();

        $totalNodes = $dom->getElementsByTagName('ImportoTotaleDocumento');
        $totalAmount = $totalNodes->length > 0 ? (float) $totalNodes->item(0)->nodeValue : 0.0;

        $currencyNodes = $dom->getElementsByTagName('Divisa');
        $currency = $currencyNodes->length > 0 ? $currencyNodes->item(0)->nodeValue : 'EUR';

        // Supplier info
        $supplierVatNodes = $dom->getElementsByTagName('IdCodice');
        $supplierVat = $supplierVatNodes->length > 0 ? $supplierVatNodes->item(0)->nodeValue : null;

        $supplier = null;
        if ($supplierVat) {
            $supplier = Contact::where('vat_number', $supplierVat)->first();
        }

        if ($supplier === null) {
            $denominazioneNodes = $dom->getElementsByTagName('Denominazione');
            $supplierName = $denominazioneNodes->length > 0 ? $denominazioneNodes->item(0)->nodeValue : 'Fornitore Esterno';
            $supplier = Contact::create([
                'type' => 'supplier',
                'country' => 'IT',
                'company_name' => $supplierName,
                'vat_number' => $supplierVat,
            ]);
        }

        $totalTax = 0.0;

        foreach ($dom->getElementsByTagName('Imposta') as $taxNode) {
            $totalTax += (float) $taxNode->nodeValue;
        }

        $passiveInvoice = DB::transaction(function () use ($supplier, $invoiceNumber, $invoiceDate, $totalAmount, $totalTax, $currency, $content): PassiveInvoice {
            $invoice = PassiveInvoice::create([
                'supplier_id' => $supplier->id,
                'invoice_number' => $invoiceNumber,
                'date' => $invoiceDate,
                'total_amount' => $totalAmount,
                'total_net' => round($totalAmount - $totalTax, 2),
                'total_tax' => round($totalTax, 2),
                'currency' => $currency,
                'payment_status' => 'unpaid',
                'sdi_lot_id' => 'SDI-IMP-'.now()->timestamp,
                'is_reconciled' => false,
                'xml_payload' => $content,
            ]);

            app(PostPassiveInvoiceAction::class)->execute($invoice);

            return $invoice;
        });

        return $this->successResponse(
            data: [
                'id' => $passiveInvoice->id,
                'invoice_number' => $invoiceNumber,
                'date' => $invoiceDate,
                'total_amount' => $totalAmount,
                'supplier' => $supplier->company_name,
            ],
            message: 'Italian FatturaPA XML parsed and registered successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Scan & decode raw Swiss QR-Bill text payload into a passive invoice.
     */
    #[OA\Post(
        path: '/api/v1/purchases/passive-invoices/scan-qr-bill',
        operationId: 'scanQrBillPassiveInvoice',
        description: 'Scan and decode raw Swiss QR-Bill text payload adhering to Swiss Payments Code (SPC) specification into a recorded passive invoice.',
        summary: 'Scan Swiss QR-Bill',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['qr_payload'],
                properties: [
                    new OA\Property(property: 'qr_payload', description: 'Raw multi-line SPC QR-code text payload', type: 'string'),
                    new OA\Property(property: 'total_tax', description: 'Deductible VAT of the bill. A QR-bill does not state it: when omitted, the whole amount is posted as a cost and no VAT is deducted.', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'exchange_rate', description: 'Rate to the accounting currency, required when the bill is in another currency.', type: 'number', format: 'float', nullable: true),
                ]
            )
        ),
        tags: ['Purchasing'],
        responses: [
            new OA\Response(response: 201, description: 'Swiss QR-Bill scanned and recorded', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid Swiss QR Code or Validation Error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function scanQrBill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_payload' => ['required', 'string'],
            'total_tax' => ['nullable', 'numeric', 'min:0'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
        ]);

        $lines = preg_split('/\r\n|\r|\n/', trim($validated['qr_payload']));

        if (count($lines) < 20 || $lines[0] !== 'SPC') {
            return $this->problemDetailsResponse(
                title: 'Invalid Swiss QR Code',
                detail: 'The provided payload does not adhere to the Swiss Payments Code (SPC) specification.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $qrIban = $lines[3] ?? '';
        $creditorName = $lines[5] ?? 'Swiss Creditor';
        $amount = (float) ($lines[18] ?? 0.0);
        $currency = $lines[19] ?? 'CHF';
        // SPC 0200: element 27 is the reference type (QRR, SCOR, NON), element 28 the reference, empty with NON.
        $referenceType = $lines[27] ?? '';
        $scorRef = $lines[28] ?? '';

        $supplier = Contact::firstOrCreate(
            ['company_name' => $creditorName],
            ['type' => 'supplier', 'country' => 'CH', 'qr_iban' => $qrIban]
        );

        $totalTax = isset($validated['total_tax']) ? round((float) $validated['total_tax'], 2) : 0.0;

        $passiveInvoice = DB::transaction(function () use ($supplier, $scorRef, $amount, $currency, $totalTax, $validated): PassiveInvoice {
            $invoice = PassiveInvoice::create([
                'supplier_id' => $supplier->id,
                'invoice_number' => 'QR-'.($scorRef !== '' ? $scorRef : strtoupper(substr(hash('sha256', $validated['qr_payload']), 0, 16))),
                'date' => now()->toDateString(),
                'total_amount' => $amount,
                'total_net' => round($amount - $totalTax, 2),
                'total_tax' => $totalTax,
                'exchange_rate' => $validated['exchange_rate'] ?? null,
                'currency' => $currency,
                'payment_status' => 'unpaid',
                'is_reconciled' => false,
                'qr_raw_payload' => $validated['qr_payload'],
            ]);

            app(PostPassiveInvoiceAction::class)->execute($invoice);

            return $invoice;
        });

        return $this->successResponse(
            data: [
                'id' => $passiveInvoice->id,
                'creditor' => $creditorName,
                'qr_iban' => $qrIban,
                'reference_type' => $referenceType,
                'scor_reference' => $scorRef,
                'amount' => $amount,
                'currency' => $currency,
            ],
            message: 'Swiss QR-Bill scanned and recorded',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Export supplier invoices to an ISO 20022 pain.001 file through Treasury.
     */
    #[OA\Post(
        path: '/api/v1/purchases/export-pain001',
        operationId: 'exportPain001PaymentOrders',
        description: 'Exports the open balance of the selected supplier invoices into a pain.001.001.09 payment file through Treasury. The batch becomes exported: nothing is paid until a pain.002 import or a manual confirmation on the batch. Requires an active bank account and the IBAN of every supplier.',
        summary: 'Export pain.001 XML',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['invoice_ids', 'format'],
                properties: [
                    new OA\Property(property: 'invoice_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    new OA\Property(property: 'format', type: 'string', enum: ['sepa_pain_001_it', 'iso_pain_001_ch']),
                    new OA\Property(property: 'execution_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'bank_account_id', description: 'Treasury bank account to debit; default: the active account in the currency of the invoices.', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        tags: ['Purchasing'],
        responses: [
            new OA\Response(response: 200, description: 'ISO 20022 pain.001 XML generated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function exportPain001(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'format' => ['required', 'in:sepa_pain_001_it,iso_pain_001_ch'],
            'execution_date' => ['nullable', 'date'],
            'bank_account_id' => ['nullable', 'uuid'],
        ]);

        return $this->successResponse(
            data: $this->paymentFiles->export($validated, (string) $request->user()?->id, $request->ip()),
            message: 'ISO 20022 pain.001 XML generated successfully'
        );
    }
}
