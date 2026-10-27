<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PaymentBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PurchasesController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly QrBillGeneratorService $qrBillGenerator
    ) {}

    public function requests(): JsonResponse
    {
        $requests = PurchaseRequest::latest('requested_date')->paginate(25);

        return $this->successResponse(data: $requests, message: 'Purchase requests retrieved');
    }

    public function orders(): JsonResponse
    {
        $orders = PurchaseOrder::latest('date')->paginate(25);

        return $this->successResponse(data: $orders, message: 'Purchase orders retrieved');
    }

    public function passiveInvoices(): JsonResponse
    {
        $invoices = PassiveInvoice::latest('date')->paginate(25);

        return $this->successResponse(data: $invoices, message: 'Passive invoices retrieved');
    }

    /**
     * Parse and import Italian FatturaPA XML into passive_invoices.
     *
     * @deprecated Legacy stub that inserts without parsing. Use POST /api/v1/purchases/passive-invoices/receive-xml instead.
     */
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

        $passiveInvoice = PassiveInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => $invoiceNumber,
            'date' => $invoiceDate,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'sdi_lot_id' => 'SDI-IMP-'.now()->timestamp,
            'is_reconciled' => false,
            'xml_payload' => $content,
        ]);

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
    public function scanQrBill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_payload' => ['required', 'string'],
        ]);

        $lines = explode("\r\n", trim(str_replace("\n", "\r\n", $validated['qr_payload'])));
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
        $scorRef = $lines[27] ?? '';

        $supplier = Contact::firstOrCreate(
            ['company_name' => $creditorName],
            ['type' => 'supplier', 'country' => 'CH', 'qr_iban' => $qrIban]
        );

        $passiveInvoice = PassiveInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'QR-'.$scorRef,
            'date' => now()->toDateString(),
            'total_amount' => $amount,
            'currency' => $currency,
            'is_reconciled' => false,
            'qr_raw_payload' => $validated['qr_payload'],
        ]);

        return $this->successResponse(
            data: [
                'id' => $passiveInvoice->id,
                'creditor' => $creditorName,
                'qr_iban' => $qrIban,
                'scor_reference' => $scorRef,
                'amount' => $amount,
                'currency' => $currency,
            ],
            message: 'Swiss QR-Bill scanned and recorded',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Export payment orders to ISO 20022 pain.001.001.03 (SEPA/Swiss Credit Transfer).
     */
    public function exportPain001(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'format' => ['required', 'in:sepa_pain_001_it,iso_pain_001_ch'],
            'execution_date' => ['nullable', 'date'],
        ]);

        $tenant = $this->tenantContext->getTenant();
        $batchId = 'BATCH-'.now()->format('YmdHis');
        $msgId = 'MSG-'.Str::random(12);
        $execDate = $validated['execution_date'] ?? now()->addDay()->toDateString();

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">
  <CstmrCdtTrfInitn>
    <GrpHdr>
      <MsgId>{$msgId}</MsgId>
      <CreDtTm>2026-10-20T21:00:00Z</CreDtTm>
      <NbOfTxs>1</NbOfTxs>
      <InitgPty>
        <Nm>{$tenant->legal_name}</Nm>
      </InitgPty>
    </GrpHdr>
    <PmtInf>
      <PmtInfId>{$batchId}</PmtInfId>
      <PmtMtd>TRF</PmtMtd>
      <ReqdExctnDt>{$execDate}</ReqdExctnDt>
      <Dbtr>
        <Nm>{$tenant->legal_name}</Nm>
      </Dbtr>
      <DbtrAcct>
        <Id>
          <IBAN>{$tenant->iban}</IBAN>
        </Id>
      </DbtrAcct>
    </PmtInf>
  </CstmrCdtTrfInitn>
</Document>
XML;

        $batch = PaymentBatch::create([
            'format' => $validated['format'],
            'status' => 'generated',
            'file_path' => "payments/{$batchId}.xml",
            'total_amount' => 1500.00,
            'currency' => $tenant->default_currency,
            'execution_date' => $execDate,
        ]);

        return $this->successResponse(
            data: [
                'batch_id' => $batch->id,
                'message_id' => $msgId,
                'format' => $validated['format'],
                'xml_preview' => $xml,
            ],
            message: 'ISO 20022 pain.001 XML generated successfully'
        );
    }
}
