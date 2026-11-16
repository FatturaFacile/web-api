<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\EDocumentNetworks\Services\Peppol\PeppolBillingBuilder;
use Modules\GeneralLedger\Services\Tax\Decimal;

uses(RefreshDatabase::class);

const PEPPOL_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
const PEPPOL_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

/**
 * @return array{0: DOMDocument, 1: DOMXPath}
 */
function peppolDocument(string $xml): array
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cbc', PEPPOL_CBC);
    $xpath->registerNamespace('cac', PEPPOL_CAC);
    $xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xpath->registerNamespace('crn', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2');

    return [$dom, $xpath];
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $attributes
 */
function peppolInvoice(array $lines, array $attributes = []): Invoice
{
    $invoice = Invoice::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'contact_id' => test()->customer->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'AF-2027-0042',
        'date' => '2027-03-01',
        'due_date' => '2027-03-31',
        'currency' => 'EUR',
        'total_net' => 0,
        'total_tax' => 0,
        'total_gross' => 0,
        'jurisdiction' => 'IT',
    ], $attributes));

    foreach ($lines as $line) {
        $invoice->items()->create($line);
    }

    return $invoice->fresh();
}

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi Srl',
        'legal_name' => 'Rossi Srl',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60 X054 2811 1010 0000 0123 456',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $this->customer = Contact::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'customer',
        'company_name' => 'Beta SpA',
        'country' => 'IT',
        'vat_number' => '01234567897',
        'email' => 'beta@example.com',
    ]);
    $this->customer->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Roma 2', 'postal_code' => '00100', 'city' => 'Roma', 'state_canton' => 'RM', 'country' => 'IT']);

    $user = User::create(['name' => 'accountant', 'email' => Str::uuid().'@example.com', 'password' => Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9)]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    app(TenantContext::class)->setTenant($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('an invoice becomes a Peppol BIS Billing 3.0 UBL invoice with every mandatory element', function (): void {
    $invoice = peppolInvoice([
        ['description' => 'Consulting', 'quantity' => 3, 'unit_price' => 33.33, 'tax_rate' => 22, 'line_total' => 99.99],
        ['description' => 'Training', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22, 'discount_percentage' => 10, 'line_total' => 90],
        ['description' => 'Books', 'quantity' => 1, 'unit_price' => 10.15, 'tax_rate' => 10, 'line_total' => 10.15],
    ]);

    [$dom, $xpath] = peppolDocument(app(PeppolBillingBuilder::class)->build($invoice));
    $value = static fn (string $query): string => (string) $xpath->evaluate("string({$query})");

    expect($dom->documentElement->namespaceURI)->toBe('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2')
        ->and($dom->documentElement->localName)->toBe('Invoice')
        ->and($value('/inv:Invoice/cbc:CustomizationID'))->toBe('urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poid:3.0')
        ->and($value('/inv:Invoice/cbc:ProfileID'))->toBe('urn:fdc:peppol.eu:2017:poid:billing:01:1.0')
        ->and($value('/inv:Invoice/cbc:ID'))->toBe('AF-2027-0042')
        ->and($value('/inv:Invoice/cbc:IssueDate'))->toBe('2027-03-01')
        ->and($value('/inv:Invoice/cbc:DueDate'))->toBe('2027-03-31')
        ->and($value('/inv:Invoice/cbc:InvoiceTypeCode'))->toBe('380')
        ->and($value('/inv:Invoice/cbc:DocumentCurrencyCode'))->toBe('EUR')
        ->and($value('/inv:Invoice/cbc:BuyerReference'))->not->toBe('')
        ->and($value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID'))->toBe('IT00811720580')
        ->and($value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID/@schemeID'))->toBe('0211')
        ->and($value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'))->toBe('IT00811720580')
        ->and($value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'))->toBe('Rossi Srl')
        ->and($value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode'))->toBe('IT')
        ->and($value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:CityName'))->toBe('Milano')
        ->and($value('/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID/@schemeID'))->toBe('0211')
        ->and($value('/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'))->toBe('IT01234567897')
        ->and($value('/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName'))->toBe('Via Roma 2')
        ->and($value('/inv:Invoice/cac:PaymentMeans/cbc:PaymentMeansCode'))->toBe('30')
        ->and($value('/inv:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'))->toBe('IT60X0542811101000000123456')
        ->and((int) $xpath->evaluate('count(/inv:Invoice/cac:InvoiceLine)'))->toBe(3)
        ->and($value('/inv:Invoice/cac:InvoiceLine[2]/cac:AllowanceCharge/cbc:Amount'))->toBe('10.00')
        ->and($value('/inv:Invoice/cac:InvoiceLine[1]/cac:Item/cbc:Name'))->toBe('Consulting')
        ->and($value('/inv:Invoice/cac:InvoiceLine[1]/cac:Item/cac:ClassifiedTaxCategory/cbc:ID'))->toBe('S');
});

test('the tax subtotals and the monetary totals reconcile exactly with the lines', function (): void {
    $invoice = peppolInvoice([
        ['description' => 'A', 'quantity' => 3, 'unit_price' => 33.33, 'tax_rate' => 22, 'line_total' => 99.99],
        ['description' => 'B', 'quantity' => 1, 'unit_price' => 0.07, 'tax_rate' => 22, 'line_total' => 0.07],
        ['description' => 'C', 'quantity' => 1, 'unit_price' => 10.15, 'tax_rate' => 10, 'line_total' => 10.15],
    ]);

    [, $xpath] = peppolDocument(app(PeppolBillingBuilder::class)->build($invoice));
    $amount = static fn (string $query): string => (string) $xpath->evaluate("string({$query})");

    $subtotals = $xpath->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal');
    expect($subtotals->length)->toBe(2);

    $taxable = '0';
    $tax = '0';

    foreach ($subtotals as $subtotal) {
        $base = (string) $xpath->evaluate('string(cbc:TaxableAmount)', $subtotal);
        $rate = (string) $xpath->evaluate('string(cac:TaxCategory/cbc:Percent)', $subtotal);
        $subtotalTax = (string) $xpath->evaluate('string(cbc:TaxAmount)', $subtotal);

        expect($subtotalTax)->toBe(Decimal::round2(Decimal::div(Decimal::mul($base, $rate), '100')));
        $taxable = bcadd($taxable, $base, 2);
        $tax = bcadd($tax, $subtotalTax, 2);
    }

    $linesSum = '0';

    foreach ($xpath->query('/inv:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount') as $node) {
        $linesSum = bcadd($linesSum, $node->textContent, 2);
    }

    expect($linesSum)->toBe('110.21')
        ->and($taxable)->toBe($linesSum)
        ->and($amount('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'))->toBe($tax)
        ->and($amount('/inv:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'))->toBe($linesSum)
        ->and($amount('/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'))->toBe($linesSum)
        ->and($amount('/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'))->toBe(bcadd($linesSum, $tax, 2))
        ->and($amount('/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe(bcadd($linesSum, $tax, 2))
        ->and($amount('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount/@currencyID'))->toBe('EUR');
});

test('a credit note becomes a UBL credit note with type code 381 and a reference to the invoice', function (): void {
    $original = peppolInvoice([['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]]);
    $credit = peppolInvoice(
        [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]],
        ['invoice_type' => 'credit_note', 'invoice_number' => 'AF-2027-0043', 'related_invoice_id' => $original->id],
    );

    [$dom, $xpath] = peppolDocument(app(PeppolBillingBuilder::class)->build($credit));
    $value = static fn (string $query): string => (string) $xpath->evaluate("string({$query})");

    expect($dom->documentElement->namespaceURI)->toBe('urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2')
        ->and($dom->documentElement->localName)->toBe('CreditNote')
        ->and($value('/crn:CreditNote/cbc:CreditNoteTypeCode'))->toBe('381')
        ->and($value('/crn:CreditNote/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'))->toBe('AF-2027-0042')
        ->and($value('/crn:CreditNote/cac:CreditNoteLine/cbc:CreditedQuantity/@unitCode'))->toBe('C62')
        ->and($value('/crn:CreditNote/cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('122.00')
        ->and($value('/crn:CreditNote/cac:PaymentMeans/cbc:PaymentDueDate'))->toBe('2027-03-31')
        ->and((int) $xpath->evaluate('count(/crn:CreditNote/cbc:DueDate)'))->toBe(0);
});

test('zero rate lines carry the category and the exemption code of their nature', function (string $nature, string $category, string $exemption): void {
    $invoice = peppolInvoice(
        [['description' => 'Export', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0, 'line_total' => 100]],
        ['vat_nature' => $nature],
    );

    [, $xpath] = peppolDocument(app(PeppolBillingBuilder::class)->build($invoice));

    expect((string) $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:ID)'))->toBe($category)
        ->and((string) $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode)'))->toBe($exemption)
        ->and((string) $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cbc:TaxAmount)'))->toBe('0.00')
        ->and((string) $xpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'))->toBe('100.00');
})->with([
    'exempt' => ['N4', 'E', 'VATEX-EU-132'],
    'export' => ['N3.1', 'G', 'VATEX-EU-G'],
    'out of scope' => ['N2.2', 'O', 'VATEX-EU-O'],
]);

test('a reverse charge invoice is category AE with zero tax', function (): void {
    $invoice = peppolInvoice(
        [['description' => 'Works', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 22, 'line_total' => 500]],
        ['reverse_charge' => true],
    );

    [, $xpath] = peppolDocument(app(PeppolBillingBuilder::class)->build($invoice));

    expect((string) $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:ID)'))->toBe('AE')
        ->and((string) $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode)'))->toBe('VATEX-EU-AE')
        ->and((string) $xpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'))->toBe('500.00');
});

test('a plain zero rate line is category Z and a foreign customer without a scheme is addressed by email', function (): void {
    $this->customer->update(['country' => 'US', 'vat_number' => null]);
    $this->customer->addresses()->update(['country' => 'US']);
    $invoice = peppolInvoice([['description' => 'Goods', 'quantity' => 2, 'unit_price' => 5, 'tax_rate' => 0, 'line_total' => 10]]);

    $builder = app(PeppolBillingBuilder::class);
    [, $xpath] = peppolDocument($builder->build($invoice));

    expect((string) $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:ID)'))->toBe('Z')
        ->and((string) $xpath->evaluate('string(/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID/@schemeID)'))->toBe('EM')
        ->and($builder->recipientIdentifier($invoice))->toBe('EM:beta@example.com');
});

test('missing master data is refused instead of invented', function (): void {
    $this->tenant->update(['settings' => []]);
    $invoice = peppolInvoice([['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]]);

    expect(fn () => app(PeppolBillingBuilder::class)->build($invoice))->toThrow(InvalidArgumentException::class, 'tenant street');
});

test('the endpoint downloads the UBL of an invoice and refuses what cannot be built', function (): void {
    $invoice = peppolInvoice([['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]]);

    $response = $this->get("/api/v1/edocument-networks/peppol/invoices/{$invoice->id}/ubl")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->headers->get('Content-Disposition'))->toContain('peppol_AF-2027-0042.xml');
    [, $xpath] = peppolDocument($response->getContent());
    expect((string) $xpath->evaluate('string(/inv:Invoice/cbc:InvoiceTypeCode)'))->toBe('380');

    $this->getJson('/api/v1/edocument-networks/peppol/invoices/'.Str::uuid().'/ubl')->assertNotFound();

    $this->tenant->update(['vat_number' => null]);
    $this->getJson("/api/v1/edocument-networks/peppol/invoices/{$invoice->id}/ubl")->assertUnprocessable();
});
