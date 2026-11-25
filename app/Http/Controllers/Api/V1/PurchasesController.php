<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchases\ReceivePassiveInvoice;
use App\Contracts\SwissQrBill\SwissQrBillParserInterface;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
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
        private readonly ExportSupplierPaymentsAction $paymentFiles,
        private readonly PostPassiveInvoiceAction $passiveInvoices
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
            new OA\Response(response: 200, description: 'Purchase requests retrieved successfully (the paginator is inside data: rows in data.data)', content: new OA\JsonContent(ref: '#/components/schemas/PurchasesRequestsListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function requests(): JsonResponse
    {
        $requests = PurchaseRequest::with('user')->latest('requested_date')->gridPaginate(RequestedPerPage::resolve(25));

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
            new OA\Response(response: 200, description: 'Purchase orders retrieved successfully (the paginator is inside data: rows in data.data)', content: new OA\JsonContent(ref: '#/components/schemas/PurchasesOrdersListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function orders(): JsonResponse
    {
        $orders = PurchaseOrder::with(['supplier', 'items'])->latest('date')->gridPaginate(RequestedPerPage::resolve(25));

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
        $invoices = PassiveInvoice::with('supplier')->latest('date')->gridPaginate(RequestedPerPage::resolve(25));

        return $this->successResponse(data: $invoices, message: 'Passive invoices retrieved');
    }

    /**
     * Import an Italian FatturaPA XML into passive invoices.
     *
     * @deprecated Alias of POST /api/v1/purchases/passive-invoices/receive-xml, kept for old clients: it runs the same import.
     */
    #[OA\Post(
        path: '/api/v1/purchases/passive-invoices/import-sdi',
        operationId: 'importSdiPassiveInvoice',
        description: 'Deprecated alias of receive-xml. The file is parsed with the same FatturaPA reader: the supplier comes from CedentePrestatore, the document type, withholding, stamp duty, split payment and lines are stored, the addressee must be the active company and a document already received is not duplicated.',
        summary: 'Import SDI XML (deprecated)',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Purchasing'],
        responses: [
            new OA\Response(response: 200, description: 'Document already received', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 201, description: 'Passive invoice imported successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid XML or invoice addressed to another company', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
        deprecated: true
    )]
    public function importSdi(Request $request, ReceivePassiveInvoice $receive): JsonResponse
    {
        $request->validate([
            'xml_file' => ['required', 'file', 'mimes:xml,txt', 'max:10240'],
        ]);

        $file = $request->file('xml_file');

        try {
            $results = $receive->execute((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (InvalidArgumentException $exception) {
            return $this->problemDetailsResponse(
                title: 'Invalid XML File',
                detail: $exception->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $first = $results[0]['invoice'];
        $created = collect($results)->contains(static fn (array $result): bool => ! $result['duplicate']);

        return $this->successResponse(
            data: [
                'id' => $first->id,
                'invoice_number' => $first->invoice_number,
                'date' => $first->date->toDateString(),
                'total_amount' => $first->total_amount,
                'supplier' => $first->supplier?->company_name,
                'duplicate' => ! $created,
                'documents' => count($results),
            ],
            message: 'Italian FatturaPA XML parsed and registered successfully',
            statusCode: $created ? Response::HTTP_CREATED : Response::HTTP_OK
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
    public function scanQrBill(Request $request, SwissQrBillParserInterface $parser): JsonResponse
    {
        $validated = $request->validate([
            'qr_payload' => ['required', 'string'],
            'total_tax' => ['nullable', 'numeric', 'min:0'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
        ]);

        $bill = $parser->parsePayload($validated['qr_payload']);

        if (! $bill->valid) {
            return $this->problemDetailsResponse(
                title: 'Invalid Swiss QR Code',
                detail: 'The payload breaks the Swiss QR-bill specification: '.implode(', ', $bill->errors).'.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $qrIban = $bill->iban;
        $creditorName = (string) ($bill->creditor['name'] ?? 'Swiss Creditor');
        $amount = (float) ($bill->amount ?? 0.0);
        $currency = $bill->currency;
        $referenceType = $bill->referenceType;
        $scorRef = (string) ($bill->reference ?? '');

        // The same bill (creditor account, reference, amount and currency) is booked once, however often it is scanned.
        $documentHash = hash('sha256', implode('|', [$qrIban, $referenceType, $scorRef, number_format($amount, 2, '.', ''), $currency, $creditorName, (string) $bill->message]));
        $existing = PassiveInvoice::withTrashed()->where('document_hash', $documentHash)->first();

        if ($existing !== null) {
            return $this->successResponse(
                data: [
                    'id' => $existing->id,
                    'creditor' => $creditorName,
                    'qr_iban' => $qrIban,
                    'is_qr_iban' => $bill->isQrIban,
                    'reference_type' => $referenceType,
                    'reference' => $scorRef,
                    'scor_reference' => $scorRef,
                    'amount' => $amount,
                    'currency' => $currency,
                    'duplicate' => true,
                ],
                message: 'Swiss QR-Bill already recorded'
            );
        }

        $supplier = Contact::firstOrCreate(
            ['company_name' => $creditorName],
            ['type' => 'supplier', 'country' => (string) ($bill->creditor['country'] ?? 'CH'), 'qr_iban' => $bill->isQrIban ? $qrIban : null]
        );

        $totalTax = isset($validated['total_tax']) ? round((float) $validated['total_tax'], 2) : 0.0;

        $passiveInvoice = $this->passiveInvoices->register([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'QR-'.($scorRef !== '' ? $scorRef : strtoupper(substr($documentHash, 0, 16))),
            'date' => now()->toDateString(),
            'total_amount' => $amount,
            'total_net' => round($amount - $totalTax, 2),
            'total_tax' => $totalTax,
            'exchange_rate' => $validated['exchange_rate'] ?? null,
            'currency' => $currency,
            'payment_status' => 'unpaid',
            'is_reconciled' => false,
            'document_hash' => $documentHash,
            'qr_raw_payload' => $validated['qr_payload'],
        ]);

        return $this->successResponse(
            data: [
                'id' => $passiveInvoice->id,
                'creditor' => $creditorName,
                'qr_iban' => $qrIban,
                'is_qr_iban' => $bill->isQrIban,
                'reference_type' => $referenceType,
                'reference' => $scorRef,
                'scor_reference' => $scorRef,
                'amount' => $amount,
                'currency' => $currency,
                'duplicate' => false,
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
