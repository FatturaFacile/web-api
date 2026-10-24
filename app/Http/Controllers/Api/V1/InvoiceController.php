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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly FatturaPaBuilderService $fatturaPaBuilder,
        private readonly QrBillGeneratorService $qrBillGenerator
    ) {}

    /**
     * List paginated invoices with optional status/jurisdiction filters.
     */
    public function index(Request $request): JsonResponse
    {
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
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
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
    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::with(['contact.addresses', 'items', 'sdiMetadata', 'qrBillData'])->findOrFail($id);

        return $this->successResponse(
            data: new InvoiceResource($invoice),
            message: 'Invoice details retrieved successfully'
        );
    }

    /**
     * Generate official Italian SDI electronic invoice XML (FatturaPA v1.2.2).
     */
    public function generateSdiXml(string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenant', 'contact.addresses', 'items'])->findOrFail($id);

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
    public function generateQrBill(string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenant', 'contact.addresses'])->findOrFail($id);

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
    public function downloadQrBill(string $id): HttpResponse
    {
        $invoice = Invoice::with(['qrBillData', 'tenant', 'contact'])->findOrFail($id);

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
}
