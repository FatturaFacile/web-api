<?php

declare(strict_types=1);

use App\Actions\Sdi\CreateSelfInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\SDI\Enums\CausalePagamento;
use Modules\SDI\Enums\NaturaIva;
use Modules\SDI\Enums\RegimeFiscale;
use Modules\SDI\Enums\TipoCassa;
use Modules\SDI\Enums\TipoDocumento;
use Modules\SDI\Enums\TipoRitenuta;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\SDI\Services\SdiPreflight;

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
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);

    $this->supplier = Contact::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'supplier',
        'company_name' => 'Acme GmbH',
        'country' => 'DE',
        'vat_number' => '123456789',
    ]);
    $this->supplier->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Hauptstrasse 1', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE']);

    app(TenantContext::class)->setTenant($this->tenant);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function typedInvoice(Contact $contact, array $attributes = [], float $rate = 22.0): Invoice
{
    $invoice = Invoice::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'AF-2027-0001',
        'date' => '2027-03-01',
        'due_date' => '2027-03-31',
        'currency' => 'EUR',
        'total_net' => 100,
        'total_tax' => $rate,
        'total_gross' => 100 + $rate,
        'jurisdiction' => 'IT',
    ], $attributes));
    $invoice->items()->create(['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => $rate, 'line_total' => 100]);

    return $invoice->load(['tenant', 'contact', 'items']);
}

/**
 * @return list<string>
 */
function xsdEnumeration(string $type): array
{
    $document = new DOMDocument;
    $document->load(base_path('modules/06_SDI/resources/xsd/Schema_del_file_xml_FatturaPA_v1.2.3.xsd'));
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
    $values = [];

    foreach ($xpath->query("//xs:simpleType[@name='{$type}']//xs:enumeration/@value") as $value) {
        $values[] = $value->nodeValue;
    }

    return $values;
}

test('the enums hold exactly the values of the official schema', function (string $type, array $cases): void {
    expect(array_column($cases, 'value'))->toEqualCanonicalizing(xsdEnumeration($type));
})->with([
    'TipoDocumento' => ['TipoDocumentoType', TipoDocumento::cases()],
    'RegimeFiscale' => ['RegimeFiscaleType', RegimeFiscale::cases()],
    'Natura' => ['NaturaType', NaturaIva::cases()],
    'TipoRitenuta' => ['TipoRitenutaType', TipoRitenuta::cases()],
    'TipoCassa' => ['TipoCassaType', TipoCassa::cases()],
    'CausalePagamento' => ['CausalePagamentoType', CausalePagamento::cases()],
]);

test('every document type passes the schema validator', function (TipoDocumento $type): void {
    $customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234']);
    $customer->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);

    if ($type === TipoDocumento::Td28) {
        $this->supplier->update(['country' => 'SM']);
    }

    $contact = $type->swapsParties() ? $this->supplier : $customer;
    $attributes = ['document_type' => $type->value, 'linked_document_number' => 'F-77', 'linked_document_date' => '2027-02-15'];

    if ($type === TipoDocumento::Td04) {
        $attributes = ['invoice_type' => 'credit_note'];
    }

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(typedInvoice($contact, $attributes), '0000A');

    expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
        ->and($xml)->toContain("<TipoDocumento>{$type->value}</TipoDocumento>");
})->with(TipoDocumento::cases());

test('a self-invoice swaps the parties and refers to the document it integrates', function (): void {
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(typedInvoice($this->supplier, [
        'document_type' => 'TD17', 'linked_document_number' => 'F-77', 'linked_document_date' => '2027-02-15',
    ]), '0000A');
    $document = new SimpleXMLElement($xml);
    $header = $document->FatturaElettronicaHeader;
    $body = $document->FatturaElettronicaBody;

    expect((string) $header->CedentePrestatore->DatiAnagrafici->IdFiscaleIVA->IdPaese)->toBe('DE')
        ->and((string) $header->CedentePrestatore->DatiAnagrafici->RegimeFiscale)->toBe('RF18')
        ->and((string) $header->CessionarioCommittente->DatiAnagrafici->IdFiscaleIVA->IdCodice)->toBe('00811720580')
        ->and((string) $header->SoggettoEmittente)->toBe('CC')
        ->and((string) $header->DatiTrasmissione->PECDestinatario)->toBe('rossi@pec.example.it')
        ->and((string) $body->DatiGenerali->DatiFattureCollegate->IdDocumento)->toBe('F-77')
        ->and((string) $body->DatiGenerali->DatiFattureCollegate->Data)->toBe('2027-02-15')
        ->and(count($body->DatiPagamento))->toBe(0);
});

test('a document the tenant addresses to itself has the tenant on both sides', function (TipoDocumento $type): void {
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(typedInvoice($this->supplier, ['document_type' => $type->value, 'linked_document_number' => 'F-1']), '0000A');
    $header = (new SimpleXMLElement($xml))->FatturaElettronicaHeader;

    expect((string) $header->CedentePrestatore->DatiAnagrafici->IdFiscaleIVA->IdCodice)->toBe('00811720580')
        ->and((string) $header->CessionarioCommittente->DatiAnagrafici->IdFiscaleIVA->IdCodice)->toBe('00811720580');
})->with([TipoDocumento::Td21, TipoDocumento::Td27]);

test('a self-invoice from a received invoice refers to it and cannot be issued twice', function (): void {
    fiscalCalendar($this->tenant);

    $source = PassiveInvoice::create([
        'tenant_id' => $this->tenant->id, 'supplier_id' => $this->supplier->id, 'invoice_number' => 'DE-2027-5', 'date' => '2027-02-20',
        'total_amount' => 1000, 'total_net' => 1000, 'total_tax' => 0, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid',
    ]);

    $action = app(CreateSelfInvoice::class);
    $invoice = $action->execute($source, ['document_type' => 'TD17', 'date' => '2027-03-10', 'tax_rate' => 22]);

    expect($invoice->document_type)->toBe('TD17')
        ->and($invoice->source_passive_invoice_id)->toBe($source->id)
        ->and($invoice->invoice_number)->toStartWith('AF-')
        ->and((float) $invoice->total_gross)->toBe(1220.0)
        ->and($invoice->payment_status)->toBe('paid')
        ->and($invoice->amountDue())->toBe('0.00');

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice->load(['tenant', 'contact', 'items', 'sourcePassiveInvoice']), '0000B');

    expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
        ->and($xml)->toContain('<IdDocumento>DE-2027-5</IdDocumento>');

    expect(fn () => $action->execute($source, ['document_type' => 'TD17', 'date' => '2027-03-10', 'tax_rate' => 22]))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($source, ['document_type' => 'TD24', 'date' => '2027-03-10', 'tax_rate' => 22]))->toThrow(ValidationException::class);
});

test('a type that needs a reference or a foreign supplier is refused at issue', function (): void {
    fiscalCalendar($this->tenant);
    $this->supplier->update(['country' => 'IT']);

    $errors = fn (array $data): array => rescue(function () use ($data): array {
        app(InvoiceService::class)->createInvoice([
            'contact_id' => $this->supplier->id, 'invoice_type' => 'standard', 'date' => '2027-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT',
            'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
            ...$data,
        ]);

        return [];
    }, fn (Throwable $e): array => $e instanceof ValidationException ? $e->errors()['invoice'] : [$e->getMessage()], false);

    expect(implode(' ', $errors(['document_type' => 'TD17', 'linked_document_number' => 'F-1'])))->toContain('supplier cannot be from IT')
        ->and(implode(' ', $errors(['document_type' => 'TD16'])))->toContain('TD16 needs a reference')
        ->and(implode(' ', $errors(['document_type' => 'TD28', 'linked_document_number' => 'F-1'])))->toContain('supplier must be from SM')
        ->and(implode(' ', $errors(['document_type' => 'TD04'])))->toContain('TD04 can only be used on a credit note');
});

test('the preflight rejects missing master data instead of inventing it, on every channel', function (): void {
    $this->tenant->update(['settings' => [], 'vat_number' => null]);
    $customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Senza Dati', 'country' => 'IT']);
    $invoice = typedInvoice($customer);

    try {
        app(SdiPreflight::class)->assertMasterData($invoice);
        $this->fail('The preflight accepted missing master data.');
    } catch (ValidationException $e) {
        expect($e->errors()['invoice'])->toHaveCount(6);
    }

    expect(fn () => app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice, '0000A'))->toThrow(InvalidArgumentException::class);
});

test('the builder holds no invented master data any more', function (): void {
    $source = file_get_contents(app_path('Services/ItalianSdi/FatturaPaBuilderService.php'));

    foreach (['Via Roma', 'Via Dante', '20121', '00100', 'Milano', "'Roma'", "?: '00000000000'", '99999999999'] as $invented) {
        expect($source)->not->toContain($invented);
    }
});

test('the address printed on the documents also counts as the seat of the tenant', function (): void {
    $this->tenant->update(['settings' => ['preferences' => ['company' => ['street' => 'Corso Italia', 'house_number' => '5', 'postal_code' => '10121', 'city' => 'Torino']]]]);
    $this->supplier->update(['country' => 'IT']);
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(typedInvoice($this->supplier), '0000A');

    expect($xml)->toContain('<Indirizzo>Corso Italia 5</Indirizzo>')->and($xml)->toContain('<CAP>10121</CAP>');
});

test('a foreign postal code becomes the conventional one, and there is no province abroad', function (): void {
    $this->supplier->addresses()->update(['postal_code' => 'W1A 1AA', 'state_canton' => 'LN']);
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(typedInvoice($this->supplier->refresh(), ['document_type' => 'TD17', 'linked_document_number' => 'F-1']), '0000A');

    expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
        ->and($xml)->toContain('<CAP>00000</CAP>')
        ->and($xml)->not->toContain('<Provincia>LN</Provincia>');
});

test('new columns are additive: an old row reads as an ordinary invoice', function (): void {
    $invoice = typedInvoice($this->supplier);

    DB::table('invoices')->where('id', $invoice->id)->update(['document_type' => null]);

    expect(TipoDocumento::ofInvoice($invoice->refresh()))->toBe(TipoDocumento::Td01)
        ->and($invoice->amountDue())->toBe('122.00');
});
