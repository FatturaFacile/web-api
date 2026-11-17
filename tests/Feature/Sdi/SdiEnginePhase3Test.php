<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\SDI\Services\FatturaPaHtmlRenderer;
use Modules\SDI\Services\FatturaPaSchemaValidator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi Srl',
        'legal_name' => 'Rossi Srl',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456',
        'pec_email' => 'rossi@pec.example.it',
        'sdi_channel' => 'manual',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);

    $this->travelTo('2026-03-15 10:00:00');
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret123')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->customer = phase3Customer($this->tenant, ['company_name' => 'Bianchi SpA', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234']);

    app(TenantContext::class)->setTenant($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $attributes
 */
function phase3Customer(Tenant $tenant, array $attributes): Contact
{
    $contact = Contact::create(array_merge(['tenant_id' => $tenant->id, 'country' => 'IT'], $attributes));
    $contact->addresses()->create([
        'tenant_id' => $tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100',
        'city' => 'Roma', 'country' => $attributes['country'] ?? 'IT',
    ]);

    return $contact;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function phase3Invoice(Contact $contact, array $attributes = [], float $rate = 22.0): Invoice
{
    $invoice = Invoice::create(array_merge([
        'tenant_id' => $contact->tenant_id,
        'contact_id' => $contact->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency' => 'EUR',
        'total_net' => 100,
        'total_tax' => $rate,
        'total_gross' => 100 + $rate,
        'jurisdiction' => 'IT',
    ], $attributes));
    $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => $rate, 'line_total' => 100]);

    return $invoice;
}

function phase3Api(): mixed
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

it('generates an XSD-valid FPR12 file and moves the invoice to generated', function (): void {
    $invoice = phase3Invoice($this->customer);

    $response = phase3Api()->postJson("/api/v1/sdi/invoices/{$invoice->id}/generate-xml")->assertOk();

    expect($response->json('data.valid'))->toBeTrue()
        ->and($response->json('data.schema_errors'))->toBe([])
        ->and($response->json('data.sdi_status'))->toBe('generated')
        ->and($response->json('data.transmission_format'))->toBe('FPR12')
        ->and($response->json('data.file_name'))->toMatch('/^IT00811720580_[0-9A-Z]{5}\.xml$/');

    $first = $response->json('data.file_name');

    phase3Api()->postJson("/api/v1/sdi/invoices/{$invoice->id}/generate-xml")->assertOk()->assertJsonPath('data.file_name', $first);
});

it('addresses a public administration as FPA12 and a foreign customer with XXXXXXX', function (): void {
    $pa = phase3Customer($this->tenant, ['company_name' => 'Comune di Roma', 'vat_number' => '02438750586', 'sdi_code' => 'UFABCD']);
    $builder = app(FatturaPaBuilderService::class);

    $paXml = $builder->buildFatturaPaXml(phase3Invoice($pa)->load(['tenant', 'contact.addresses', 'items']));

    expect($paXml)->toContain('versione="FPA12"')->toContain('<CodiceDestinatario>UFABCD</CodiceDestinatario>')
        ->and(app(FatturaPaSchemaValidator::class)->errors($paXml))->toBe([]);

    $foreign = phase3Customer($this->tenant, ['company_name' => 'Müller GmbH', 'vat_number' => 'DE123456789', 'country' => 'DE', 'sdi_code' => 'ABC1234']);
    $foreignXml = $builder->buildFatturaPaXml(phase3Invoice($foreign, ['invoice_number' => 'INV-2026-0002'], 0.0)->load(['tenant', 'contact.addresses', 'items']));

    expect($foreignXml)->toContain('<CodiceDestinatario>XXXXXXX</CodiceDestinatario>')->not->toContain('PECDestinatario');
});

it('embeds a base64 PDF in Allegati and still validates', function (): void {
    $invoice = phase3Invoice($this->customer)->load(['tenant', 'contact.addresses', 'items']);

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice, null, [
        ['name' => 'invoice.pdf', 'format' => 'PDF', 'content' => '%PDF-1.4 fake'],
    ]);

    expect($xml)->toContain('<NomeAttachment>invoice.pdf</NomeAttachment>')->toContain(base64_encode('%PDF-1.4 fake'))
        ->and(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([]);
});

it('keeps a generated file for the manual channel and hands it out for download', function (): void {
    $invoice = phase3Invoice($this->customer);
    phase3Api()->postJson("/api/v1/sdi/invoices/{$invoice->id}/generate-xml")->assertOk();

    phase3Api()->postJson("/api/v1/sdi/invoices/{$invoice->id}/send")->assertStatus(202)->assertJsonPath('data.sdi_status', 'sending');

    $download = phase3Api()->get("/api/v1/sdi/invoices/{$invoice->id}/download-xml")->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('IT00811720580_');

    // Once transmitted the file is frozen.
    phase3Api()->postJson("/api/v1/sdi/invoices/{$invoice->id}/generate-xml")->assertUnprocessable();
});

it('previews the invoice as escaped, sandboxed HTML', function (): void {
    $customer = phase3Customer($this->tenant, ['company_name' => 'Evil <script>alert(1)</script> Srl', 'vat_number' => '02438750586', 'sdi_code' => 'ABC1234']);
    $invoice = phase3Invoice($customer);

    $response = phase3Api()->get("/api/v1/sdi/invoices/{$invoice->id}/preview-html")->assertOk();

    expect($response->getContent())->toContain('CedentePrestatore')->toContain('&lt;script&gt;')->not->toContain('<script>')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('refuses to render a document that is not a FatturaPA', function (): void {
    app(FatturaPaHtmlRenderer::class)->render('<html/>');
})->throws(InvalidArgumentException::class);

it('previews and imports a supplier XML, and reads a CAdES p7m envelope', function (): void {
    $xml = supplierPhase3Xml('F-901');

    $preview = phase3Api()->postJson('/api/v1/sdi/passive/import', [
        'file' => UploadedFile::fake()->createWithContent('IT01234567897_AAAAA.xml', $xml),
        'preview_only' => true,
    ])->assertOk();
    expect($preview->json('data.preview_html'))->toContain('F-901')->and(PassiveInvoice::count())->toBe(0);

    phase3Api()->postJson('/api/v1/sdi/passive/import', [
        'file' => UploadedFile::fake()->createWithContent('IT01234567897_AAAAA.xml', $xml),
    ])->assertCreated()->assertJsonPath('data.invoices.0.duplicate', false);
    expect(PassiveInvoice::count())->toBe(1);

    phase3Api()->postJson('/api/v1/sdi/passive/import', [
        'file' => UploadedFile::fake()->createWithContent('IT01234567897_AAAAA.xml', $xml),
    ])->assertCreated()->assertJsonPath('data.invoices.0.duplicate', true);

    $p7m = (string) base64_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sdi/signed-invoice.p7m.b64'));
    phase3Api()->postJson('/api/v1/sdi/passive/import', [
        'file' => UploadedFile::fake()->createWithContent('IT01234567897_BBBBB.xml.p7m', $p7m),
        'preview_only' => true,
    ])->assertOk()->assertJsonStructure(['data' => ['preview_html', 'signature_verified']]);

    phase3Api()->postJson('/api/v1/sdi/passive/import', [
        'file' => UploadedFile::fake()->createWithContent('broken.xml', 'not xml'),
    ])->assertUnprocessable();
});

it('keeps invoices of another tenant out of reach', function (): void {
    $other = Tenant::create(['name' => 'Verdi Srl', 'legal_name' => 'Verdi Srl', 'vat_number' => '01234567897', 'fiscal_code' => '01234567897', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($other);
    $foreignInvoice = phase3Invoice(phase3Customer($other, ['company_name' => 'Neri SpA', 'vat_number' => '00811720580', 'sdi_code' => 'ABC1234']));
    app(TenantContext::class)->setTenant($this->tenant);

    phase3Api()->postJson("/api/v1/sdi/invoices/{$foreignInvoice->id}/generate-xml")->assertNotFound();
    phase3Api()->get("/api/v1/sdi/invoices/{$foreignInvoice->id}/preview-html")->assertNotFound();
    phase3Api()->get("/api/v1/sdi/invoices/{$foreignInvoice->id}/download-xml")->assertNotFound();
    phase3Api()->postJson("/api/v1/sdi/invoices/{$foreignInvoice->id}/send")->assertNotFound();
});

function supplierPhase3Xml(string $number): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567897</IdCodice></IdFiscaleIVA>
      <Anagrafica><Denominazione>Fornitore Srl</Denominazione></Anagrafica>
    </DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>00811720580</IdCodice></IdFiscaleIVA>
    </DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento>
      <TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>2026-03-01</Data><Numero>{$number}</Numero>
      <ImportoTotaleDocumento>122.00</ImportoTotaleDocumento>
    </DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi>
      <DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>100.00</ImponibileImporto><Imposta>22.00</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
    <DatiPagamento><DettaglioPagamento><DataScadenzaPagamento>2026-03-31</DataScadenzaPagamento><ImportoPagamento>122.00</ImportoPagamento></DettaglioPagamento></DatiPagamento>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
}
