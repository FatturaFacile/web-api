<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SwissEbill\Application\Dto\BuiltEbillPayload;
use Modules\SwissEbill\Domain\Exceptions\EbillValidationException;
use Modules\SwissEbill\Infrastructure\Xml\EbillPayloadBuilderService;
use Modules\SwissEbill\Infrastructure\Xml\EbillPayloadValidator;
use Modules\SwissEbill\Models\EbillSetting;
use Modules\SwissEbill\Models\EbillTransmission;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => ebSetUp($this));

/**
 * @return array<string, mixed>
 */
function ebXml(string $xml): array
{
    $document = new DOMDocument;
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('e', EbillPayloadValidator::NAMESPACE_URI);

    return ['document' => $document, 'xpath' => $xpath];
}

function ebBuild(object $test, EbillSetting $setting, Invoice $invoice): BuiltEbillPayload
{
    return app(EbillPayloadBuilderService::class)->build($invoice->load(['tenant', 'contact.addresses', 'items']), $setting, 'tx-1');
}

test('the payload carries biller, recipient, lines, VAT summary and totals and passes the schema', function (): void {
    $customer = ebCustomer();
    $invoice = ebInvoice($customer);

    $payload = ebBuild($this, $this->setting, $invoice);
    $xml = ebXml($payload->xml)['xpath'];

    (new EbillPayloadValidator)->assertValid($payload->xml);

    expect($xml->evaluate('string(/e:EbillInvoice/@version)'))->toBe('SIX eBill XML')
        ->and($xml->evaluate('string(//e:Header/e:BillerID)'))->toBe(ebBillerId())
        ->and($xml->evaluate('string(//e:Header/e:RecipientID)'))->toBe($customer->ebill_recipient_id)
        ->and($xml->evaluate('string(//e:Header/e:Channel)'))->toBe('B2B')
        ->and($xml->evaluate('string(//e:Header/e:TransmissionId)'))->toBe('tx-1')
        ->and($xml->evaluate('string(//e:Biller/e:UID)'))->toBe($this->tenant->uid_number)
        ->and($xml->evaluate('string(//e:Biller/e:PaymentAccount/e:IBAN)'))->toBe(EBILL_QR_IBAN)
        ->and($xml->evaluate('string(//e:Biller/e:PaymentAccount/e:Reference/@type)'))->toBe('QRR')
        ->and($xml->evaluate('string(//e:Biller/e:PaymentAccount/e:Reference)'))->toBe(EBILL_QRR)
        ->and($xml->evaluate('string(//e:Biller/e:Address/e:PostalCode)'))->toBe('8000')
        ->and($xml->evaluate('string(//e:Biller/e:Address/e:City)'))->toBe('Zürich')
        ->and($xml->evaluate('string(//e:Recipient/e:UID)'))->toBe($customer->uid_number)
        ->and($xml->evaluate('string(//e:Recipient/e:Address/e:Street)'))->toBe('Bahnhofstrasse')
        ->and($xml->evaluate('string(//e:Invoice/e:Number)'))->toBe($invoice->invoice_number)
        ->and($xml->evaluate('string(//e:Invoice/e:IssueDate)'))->toBe('2026-03-01')
        ->and($xml->evaluate('string(//e:Invoice/e:DueDate)'))->toBe('2026-03-31')
        ->and($xml->evaluate('string(//e:Invoice/e:Currency)'))->toBe('CHF')
        ->and((float) $xml->evaluate('string(//e:Line/e:VatRate)'))->toBe(8.1)
        ->and($xml->evaluate('string(//e:VatSummary/e:Vat/@rate)'))->toBe('8.10')
        ->and($xml->evaluate('string(//e:VatSummary/e:Vat/e:TaxAmount)'))->toBe('8.10')
        ->and($xml->evaluate('string(//e:Totals/e:Gross)'))->toBe('108.10');
});

test('the courtesy PDF is embedded in Base64 with its SHA-256 and size', function (): void {
    $payload = ebBuild($this, $this->setting, ebInvoice(ebCustomer()));
    $xml = ebXml($payload->xml)['xpath'];
    $pdf = '%PDF-1.4 courtesy';

    expect($xml->evaluate('string(//e:EmbeddedDocument/@mimeType)'))->toBe('application/pdf')
        ->and($xml->evaluate('string(//e:EmbeddedDocument/@sha256)'))->toBe(hash('sha256', $pdf))
        ->and($xml->evaluate('string(//e:EmbeddedDocument/@sizeBytes)'))->toBe((string) strlen($pdf))
        ->and(base64_decode($xml->evaluate('string(//e:EmbeddedDocument)'), true))->toBe($pdf)
        ->and($payload->pdfSha256)->toBe(hash('sha256', $pdf))
        ->and($payload->pdfSizeBytes)->toBe(strlen($pdf))
        ->and($payload->xmlSha256)->toBe(hash('sha256', $payload->xml));
});

test('without the courtesy PDF the payload has no attachment', function (): void {
    $payload = ebBuild($this, ebSettings(['attach_courtesy_pdf' => false]), ebInvoice(ebCustomer()));

    expect(ebXml($payload->xml)['xpath']->query('//e:EmbeddedDocument')->length)->toBe(0)->and($payload->pdfSha256)->toBeNull();
});

test('a B2C customer is routed on its participant id without a UID', function (): void {
    $customer = ebCustomer(['uid_number' => null, 'ebill_channel_type' => 'b2c_private']);
    $xml = ebXml(ebBuild($this, $this->setting, ebInvoice($customer))->xml)['xpath'];

    expect($xml->evaluate('string(//e:Header/e:Channel)'))->toBe('B2C')->and($xml->query('//e:Recipient/e:UID')->length)->toBe(0);
});

test('a line with several VAT rates is summarised per rate', function (): void {
    $invoice = ebInvoice(ebCustomer(), [], 100.0);
    InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'description' => 'Books', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 2.6, 'discount_percentage' => 0, 'line_total' => 50]);
    InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'description' => 'Export', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0, 'discount_percentage' => 0, 'line_total' => 10]);

    $xml = ebXml(ebBuild($this, $this->setting, $invoice->refresh())->xml)['xpath'];

    expect($xml->query('//e:Vat')->length)->toBe(3)
        ->and($xml->evaluate('string(//e:Vat[@rate="2.60"]/e:TaxAmount)'))->toBe('1.30')
        ->and($xml->evaluate('string(//e:Vat[@rate="0.00"]/e:TaxAmount)'))->toBe('0.00');
});

/**
 * @return list<string> the violation codes
 */
function ebViolations(callable $build): array
{
    try {
        $build();
    } catch (EbillValidationException $e) {
        return $e->codes();
    }

    return [];
}

test('every missing mandatory datum is reported at once', function (): void {
    $customer = ebCustomer(['ebill_recipient_id' => null, 'ebill_status' => 'not_enrolled', 'uid_number' => null, 'company_name' => null]);
    $customer->addresses()->delete();
    $this->tenant->forceFill(['uid_number' => null])->save();
    $invoice = ebInvoice($customer, ['currency' => 'USD', 'due_date' => null, 'status' => 'draft']);

    $codes = ebViolations(fn () => ebBuild($this, $this->setting, $invoice->refresh()));

    expect($codes)->toContain('invoice_not_issued', 'currency_unsupported', 'due_date_missing', 'biller_uid_missing', 'recipient_ebill_id_missing', 'recipient_not_enrolled', 'recipient_name_missing', 'recipient_address_missing');
});

test('a missing QR-IBAN and IBAN, an invalid UID and an unsupported VAT rate are named', function (): void {
    $this->tenant->forceFill(['uid_number' => 'CHE-123.456.789', 'iban' => null, 'qr_iban' => null])->save();
    $invoice = ebInvoice(ebCustomer());
    $invoice->items()->update(['tax_rate' => 19]);

    $codes = ebViolations(fn () => ebBuild($this, $this->setting, $invoice->refresh()));

    expect($codes)->toContain('biller_uid_invalid', 'biller_iban_missing', 'vat_rate_unsupported');
});

test('the legacy VAT rates are accepted for an invoice of 2023', function (): void {
    $invoice = ebInvoice(ebCustomer(), ['date' => '2023-11-01', 'due_date' => '2023-11-30', 'total_tax' => 7.7, 'total_gross' => 107.7]);
    $invoice->items()->update(['tax_rate' => 7.7]);

    expect(ebViolations(fn () => ebBuild($this, $this->setting, $invoice->refresh())))->toBe([]);
});

test('a B2B customer without a UID is refused, a disabled channel too', function (): void {
    $b2b = ebCustomer(['uid_number' => null]);

    expect(ebViolations(fn () => ebBuild($this, $this->setting, ebInvoice($b2b))))->toContain('recipient_uid_missing');

    $disabled = ebSettings(['ebill_enabled' => false]);

    expect(ebViolations(fn () => ebBuild($this, $disabled, ebInvoice(ebCustomer()))))->toContain('ebill_disabled');
});

test('an oversized payload is refused', function (): void {
    config(['swiss_ebill.max_payload_bytes' => 500]);

    expect(ebViolations(fn () => ebBuild($this, $this->setting, ebInvoice(ebCustomer()))))->toBe(['payload_too_large']);
});

test('special characters of names and descriptions are escaped in the XML', function (): void {
    $customer = ebCustomer(['company_name' => 'Müller & Söhne AG']);
    $invoice = ebInvoice($customer);
    $invoice->items()->update(['description' => 'Rahmen "A" & B <fix>']);

    $payload = ebBuild($this, $this->setting, $invoice->refresh());
    $xml = ebXml($payload->xml)['xpath'];

    expect($xml->evaluate('string(//e:Recipient/e:Name)'))->toBe('Müller & Söhne AG')
        ->and($xml->evaluate('string(//e:Line/e:Description)'))->toBe('Rahmen "A" & B <fix>');
});

test('the pre-flight endpoint lists the violations and tells whether the invoice can be sent', function (): void {
    $ready = ebInvoice(ebCustomer());
    $this->getJson('/api/v1/swiss-ebill/invoices/'.$ready->id)->assertOk()->assertJsonPath('data.violations', [])->assertJsonPath('data.can_send', true);

    $notEnrolled = ebInvoice(ebCustomer(['ebill_status' => 'not_enrolled', 'ebill_recipient_id' => null]));
    $response = $this->getJson('/api/v1/swiss-ebill/invoices/'.$notEnrolled->id)->assertOk()->assertJsonPath('data.can_send', false);

    expect(array_column($response->json('data.violations'), 'code'))->toContain('recipient_not_enrolled', 'recipient_ebill_id_missing');
});

test('sending an incomplete invoice answers 422 with the violations and stores nothing', function (): void {
    $invoice = ebInvoice(ebCustomer(['ebill_status' => 'not_enrolled', 'ebill_recipient_id' => null]));

    $response = $this->postJson('/api/v1/swiss-ebill/invoices/'.$invoice->id.'/send')->assertStatus(422)->assertJsonPath('code', 'payload_invalid');

    expect(array_column($response->json('violations'), 'code'))->toContain('recipient_not_enrolled')
        ->and(EbillTransmission::query()->count())->toBe(0)
        ->and($invoice->refresh()->ebill_status)->toBeNull();
});
