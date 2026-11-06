<?php

declare(strict_types=1);

use App\Actions\Documents\QueueDocumentEmail;
use App\Contracts\PdfGenerator;
use App\Jobs\SendDocumentEmailJob;
use App\Mail\DocumentMail;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Services\EnasarcoSummaryService;
use Modules\Invoicing\Models\DeclarationOfIntent;
use Modules\Invoicing\Services\DeclarationOfIntentService;
use Modules\SDI\Services\FatturaPaSchemaValidator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $this->user = User::create(['name' => 'Ada', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('secret123')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234', 'email' => 'bianchi@example.com']);
    $this->customer->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant);
});

/**
 * @param  array<string, mixed>  $data
 */
function issueInvoice(array $data = [], float $unitPrice = 1000.0, float $rate = 22.0): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'contact_id' => test()->customer->id,
        'invoice_type' => 'standard',
        'date' => '2027-03-01',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => $rate]],
        ...$data,
    ]);
}

function issuedXml(Invoice $invoice): string
{
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice->load(['tenant', 'contact', 'items', 'declarationOfIntent']), '0000A');

    expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([]);

    return $xml;
}

function forfettario(): void
{
    test()->tenant->update(['settings' => [...test()->tenant->settings, 'regime_fiscale' => 'RF19']]);
    app(TenantContext::class)->setTenant(test()->tenant->refresh());
}

function declarationFor(Contact $contact, array $attributes = []): DeclarationOfIntent
{
    return DeclarationOfIntent::create([
        'tenant_id' => test()->tenant->id, 'contact_id' => $contact->id, 'direction' => 'received', 'protocol_number' => '27010412345678901-000001',
        'receipt_date' => '2027-01-10', 'year' => 2027, 'plafond_amount' => 5000, 'valid_from' => '2027-01-01', 'valid_to' => '2027-12-31',
        ...$attributes,
    ]);
}

// ------------------------------------------------------------ Forfettario

test('a flat-rate invoice has nature N2.2, the wording, and the stamp duty above the threshold', function (): void {
    forfettario();

    $invoice = issueInvoice(unitPrice: 1000, rate: 0);
    $xml = issuedXml($invoice);

    expect($xml)->toContain('<RegimeFiscale>RF19</RegimeFiscale>')
        ->and($xml)->toContain('<Natura>N2.2</Natura>')
        ->and($xml)->toContain('Legge n. 190/2014')
        ->and($xml)->toContain('<BolloVirtuale>SI</BolloVirtuale>')
        ->and($xml)->toContain('<ImportoBollo>2.00</ImportoBollo>')
        ->and((float) $invoice->total_tax)->toBe(0.0)
        ->and((float) $invoice->total_gross)->toBe(1002.0)
        ->and($xml)->toContain('<ImportoTotaleDocumento>1002.00</ImportoTotaleDocumento>');
});

test('the stamp duty starts above the threshold, not at it', function (float $amount, bool $applied): void {
    forfettario();

    expect((bool) issueInvoice(unitPrice: $amount, rate: 0)->stamp_duty_applied)->toBe($applied);
})->with([
    'at the threshold' => [77.47, false],
    'a cent above' => [77.48, true],
]);

test('what cannot stand under the flat-rate regime is refused with a 422', function (array $data, float $rate, string $message): void {
    forfettario();

    try {
        issueInvoice($data, rate: $rate);
        $this->fail('The document was issued.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422)->and(implode(' ', $e->errors()['invoice']))->toContain($message);
    }

    expect(Invoice::count())->toBe(0);
})->with([
    'VAT' => [[], 22.0, 'no VAT can be charged'],
    'withholding' => [['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20], 0.0, 'not subject to withholding'],
    'split payment' => [['split_payment' => true], 0.0, 'do not apply'],
    'reverse charge' => [['reverse_charge' => true], 0.0, 'do not apply'],
    'another nature' => [['vat_nature' => 'N4'], 0.0, 'nature is N2.2'],
]);

test('the regime is validated when the company settings are saved', function (): void {
    Sanctum::actingAs($this->user);
    $api = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id]);

    $api->putJson('/api/v1/settings/tenant', ['settings' => ['regime_fiscale' => 'RF03']])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.regime_fiscale');
    $api->putJson('/api/v1/settings/tenant', ['settings' => ['regime_fiscale' => 'RF19']])->assertOk();

    expect($this->tenant->refresh()->settings['regime_fiscale'])->toBe('RF19');
});

// ------------------------------------------------------------ Cassa previdenziale

test('the fund goes in the VAT base and in schema order, with the INPS rivalsa', function (): void {
    $invoice = issueInvoice([
        'fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4,
        'withholding_tax_enabled' => true, 'withholding_tax_percent' => 20, 'fund_subject_to_withholding' => true,
    ]);
    $xml = issuedXml($invoice);

    expect((float) $invoice->fund_amount)->toBe(40.0)
        ->and((float) $invoice->total_tax)->toBe(228.8)
        ->and((float) $invoice->total_gross)->toBe(1268.8)
        ->and((float) $invoice->withholding_tax_amount)->toBe(208.0)
        ->and($invoice->amountDue())->toBe('1060.80')
        ->and($xml)->toContain('<TipoCassa>TC22</TipoCassa>')
        ->and($xml)->toContain('<AlCassa>4.00</AlCassa>')
        ->and($xml)->toContain('<ImportoContributoCassa>40.00</ImportoContributoCassa>')
        ->and($xml)->toContain('<ImponibileCassa>1000.00</ImponibileCassa>')
        ->and($xml)->toContain('<ImponibileImporto>1040.00</ImponibileImporto>')
        ->and($xml)->toContain('<Imposta>228.80</Imposta>')
        ->and($xml)->toContain('<ImportoPagamento>1060.80</ImportoPagamento>')
        ->and(strpos($xml, '<DatiRitenuta>'))->toBeLessThan(strpos($xml, '<DatiCassaPrevidenziale>'));
});

test('a flat-rate professional charges the rivalsa without VAT', function (): void {
    forfettario();

    $invoice = issueInvoice(['fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4], rate: 0);
    $xml = issuedXml($invoice);

    expect((float) $invoice->total_gross)->toBe(1042.0)
        ->and($xml)->toContain('<ImponibileImporto>1040.00</ImponibileImporto>');
});

test('a fund without type or rate is refused', function (): void {
    expect(fn () => issueInvoice(['fund_enabled' => true]))->toThrow(ValidationException::class);
});

// ------------------------------------------------------------ Enasarco

function enasarcoParameters(string $ceiling = '40000'): void
{
    EnasarcoParameter::create([
        'tenant_id' => test()->tenant->id, 'year' => 2027, 'agent_type' => 'multi_mandate', 'rate_percent' => 10,
        'agent_share_percent' => 50, 'annual_ceiling' => $ceiling, 'source' => 'Test figures, not the real ones',
    ]);
}

test('Enasarco needs the parameters of the year: no figure is assumed', function (): void {
    expect(fn () => issueInvoice(['enasarco_enabled' => true, 'enasarco_agent_type' => 'multi_mandate']))
        ->toThrow(ValidationException::class, 'No Enasarco parameters for 2027');
});

test('Enasarco is withheld as RT04 and stops at the annual ceiling', function (): void {
    enasarcoParameters('1500');

    $first = issueInvoice(['enasarco_enabled' => true, 'enasarco_agent_type' => 'multi_mandate']);
    $xml = issuedXml($first);
    $second = issueInvoice(['enasarco_enabled' => true, 'enasarco_agent_type' => 'multi_mandate']);
    $third = issueInvoice(['enasarco_enabled' => true, 'enasarco_agent_type' => 'multi_mandate']);

    expect((float) $first->enasarco_amount)->toBe(50.0)
        ->and($first->amountDue())->toBe('1170.00')
        ->and($xml)->toContain('<TipoRitenuta>RT04</TipoRitenuta>')
        ->and($xml)->toContain('<CausalePagamento>R</CausalePagamento>')
        ->and((float) $second->enasarco_base)->toBe(500.0)
        ->and((float) $second->enasarco_amount)->toBe(25.0)
        ->and((float) $third->enasarco_amount)->toBe(0.0);

    expect(issuedXml($third))->not->toContain('RT04');
});

test('the period summary adds up what was withheld per counterparty', function (): void {
    enasarcoParameters();
    issueInvoice(['enasarco_enabled' => true, 'enasarco_agent_type' => 'multi_mandate']);
    issueInvoice(['enasarco_enabled' => true, 'enasarco_agent_type' => 'multi_mandate', 'date' => '2027-05-01']);

    $quarter = app(EnasarcoSummaryService::class)->summary($this->tenant->id, 2027, 1);
    $year = app(EnasarcoSummaryService::class)->summary($this->tenant->id, 2027);

    expect($quarter['withheld_from_tenant']['total_amount'])->toBe('50.00')
        ->and($year['withheld_from_tenant']['total_amount'])->toBe('100.00')
        ->and($year['withheld_from_tenant']['by_contact'][0]['documents'])->toBe(2);
});

test('generic parameters are read-only: the tenant writes its own row', function (): void {
    DB::table('enasarco_parameters')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => null, 'year' => 2027, 'agent_type' => 'single_mandate', 'rate_percent' => 10, 'agent_share_percent' => 50, 'annual_ceiling' => 1000, 'source' => 'Generic',
    ]);

    Sanctum::actingAs($this->user);
    $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])->putJson('/api/v1/general-ledger/enasarco/parameters', [
        'year' => 2027, 'agent_type' => 'single_mandate', 'rate_percent' => 12, 'agent_share_percent' => 50, 'annual_ceiling' => 2000, 'source' => 'Own',
    ])->assertOk();

    expect(EnasarcoParameter::withoutGlobalScopes()->whereNull('tenant_id')->value('source'))->toBe('Generic')
        ->and(EnasarcoParameter::resolve($this->tenant->id, 2027, 'single_mandate')->source)->toBe('Own');
});

// ------------------------------------------------------------ Lettere di intento

test('an active declaration makes the sale non-taxable and is referenced on every line', function (): void {
    declarationFor($this->customer);

    $invoice = issueInvoice();
    $xml = issuedXml($invoice);

    expect($invoice->vat_nature)->toBe('N3.5')
        ->and((float) $invoice->total_tax)->toBe(0.0)
        ->and((float) $invoice->items->first()->tax_rate)->toBe(0.0)
        ->and($xml)->toContain('<Natura>N3.5</Natura>')
        ->and($xml)->toContain('<TipoDato>INTENTO</TipoDato>')
        ->and($xml)->toContain('<RiferimentoTesto>27010412345678901-000001</RiferimentoTesto>')
        ->and($xml)->toContain('<RiferimentoData>2027-01-10</RiferimentoData>')
        ->and($xml)->toContain('<BolloVirtuale>SI</BolloVirtuale>')
        ->and(DeclarationOfIntent::first()->consumed_amount)->toBe('1000.00');
});

test('a sale above the plafond is blocked and consumes nothing', function (): void {
    declarationFor($this->customer, ['plafond_amount' => 1500]);

    issueInvoice();

    expect(fn () => issueInvoice())->toThrow(ValidationException::class);
    expect(DeclarationOfIntent::first()->consumed_amount)->toBe('1000.00')
        ->and(Invoice::count())->toBe(1);
});

test('a declaration outside its validity does not apply, and a sale can opt out', function (): void {
    declarationFor($this->customer, ['valid_from' => '2027-06-01']);

    expect(issueInvoice()->declaration_of_intent_id)->toBeNull();

    declarationFor($this->customer, ['protocol_number' => '27010412345678901-000002']);

    expect(issueInvoice(['skip_declaration_of_intent' => true])->declaration_of_intent_id)->toBeNull()
        ->and((float) Invoice::query()->latest('sequential_number')->first()->total_tax)->toBe(220.0);
});

test('N3.5 without a declaration is refused', function (): void {
    expect(fn () => issueInvoice(['vat_nature' => 'N3.5'], rate: 0))->toThrow(ValidationException::class, 'needs a declaration of intent');
});

test('a credit note gives the plafond back', function (): void {
    $declaration = declarationFor($this->customer);
    $invoice = issueInvoice();

    issueInvoice(['invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id, 'skip_declaration_of_intent' => true], unitPrice: 400, rate: 0);

    expect($declaration->refresh()->consumed_amount)->toBe('600.00');
});

test('received documents track a declaration issued to the supplier without being refused', function (): void {
    $supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Verdi', 'country' => 'IT', 'vat_number' => '01234567897']);
    $declaration = declarationFor($supplier, ['direction' => 'issued', 'plafond_amount' => 100]);

    $invoice = PassiveInvoice::create([
        'tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'F-1', 'date' => '2027-03-01', 'total_amount' => 500,
        'total_net' => 500, 'total_tax' => 0, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid',
    ]);

    app(DeclarationOfIntentService::class)->trackPurchase($invoice);

    expect($declaration->refresh()->consumed_amount)->toBe('500.00')
        ->and($invoice->refresh()->declaration_of_intent_id)->toBe($declaration->id);
});

test('the register API validates the protocol, keeps tenants apart and sums up the year', function (): void {
    Sanctum::actingAs($this->user);
    $api = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id]);
    $payload = [
        'contact_id' => $this->customer->id, 'protocol_number' => '27010412345678901-000001', 'receipt_date' => '2027-01-10', 'year' => 2027,
        'plafond_amount' => 5000, 'valid_from' => '2027-01-01', 'valid_to' => '2027-12-31',
    ];

    $api->postJson('/api/v1/invoicing/declarations-of-intent', [...$payload, 'protocol_number' => 'ABC'])->assertUnprocessable()->assertJsonValidationErrors('protocol_number');

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other', 'country' => 'IT', 'default_currency' => 'EUR']);
    $foreign = app(TenantContext::class)->runWithTenant($other, fn () => Contact::create(['tenant_id' => $other->id, 'company_name' => 'Other', 'country' => 'IT']));
    $api->postJson('/api/v1/invoicing/declarations-of-intent', [...$payload, 'contact_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('contact_id');

    $id = $api->postJson('/api/v1/invoicing/declarations-of-intent', $payload)->assertCreated()->json('data.id');
    $api->postJson('/api/v1/invoicing/declarations-of-intent', $payload)->assertUnprocessable();

    issueInvoice();

    $api->getJson('/api/v1/invoicing/declarations-of-intent/summary?year=2027')
        ->assertOk()
        ->assertJsonPath('data.totals.received.consumed', '1000.00')
        ->assertJsonPath('data.totals.received.residual', '4000.00');
    $api->deleteJson("/api/v1/invoicing/declarations-of-intent/{$id}")->assertUnprocessable();
});

// ------------------------------------------------------------ Module flow

test('the draft and post flow of the invoicing module derives the same amounts', function (): void {
    Sanctum::actingAs($this->user);
    $api = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id]);

    $draft = $api->postJson('/api/v1/invoicing/invoices', [
        'contact_id' => $this->customer->id, 'date' => '2027-03-01', 'jurisdiction' => 'IT', 'document_type' => 'TD06',
        'fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4, 'withholding_tax_enabled' => true, 'withholding_tax_percent' => 20,
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ])->assertCreated();

    $draft->assertJsonPath('document_type', 'TD06')->assertJsonPath('fund_amount', 40)->assertJsonPath('amount_due', 1068.8);

    $api->postJson('/api/v1/invoicing/invoices/'.$draft->json('id').'/post')->assertOk();

    $invoice = Invoice::query()->findOrFail($draft->json('id'));

    expect((string) $invoice->total_gross)->toBe('1268.80')
        ->and($invoice->amountDue())->toBe('1068.80')
        ->and($invoice->status)->toBe('posted')
        ->and($invoice->gl_journal_entry_id)->not->toBeNull();
});

test('a fiscal field the schema does not know is refused by both flows', function (): void {
    Sanctum::actingAs($this->user);
    $api = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id]);
    $payload = [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => '2027-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT', 'document_type' => 'TD07',
        'vat_nature' => 'N6', 'fund_type' => 'TC99', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ];

    $api->postJson('/api/v1/sales/invoices', $payload)->assertUnprocessable()->assertJsonValidationErrors(['document_type', 'vat_nature', 'fund_type']);
    $api->postJson('/api/v1/invoicing/invoices', $payload)->assertUnprocessable()->assertJsonValidationErrors(['document_type', 'vat_nature', 'fund_type']);
});

// ------------------------------------------------------------ Bulk SDI

test('the bulk send reports the outcome of each invoice and one failure stops nothing', function (): void {
    $good = issueInvoice();
    $noAddress = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Senza Indirizzo', 'country' => 'IT', 'vat_number' => '01234567897']);
    $bad = issueInvoice(['contact_id' => $noAddress->id]);
    $unknown = (string) Str::uuid();

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other', 'country' => 'IT', 'default_currency' => 'EUR']);
    $foreign = app(TenantContext::class)->runWithTenant($other, function () use ($other): Invoice {
        $contact = Contact::create(['tenant_id' => $other->id, 'company_name' => 'Other', 'country' => 'IT']);

        return Invoice::create([
            'tenant_id' => $other->id, 'contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'X-1',
            'date' => '2027-03-01', 'currency' => 'EUR', 'total_net' => 1, 'total_tax' => 0, 'total_gross' => 1, 'jurisdiction' => 'IT',
        ]);
    });

    Sanctum::actingAs($this->user);

    $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
        ->postJson('/api/v1/sales/invoices/send-sdi-bulk', ['invoice_ids' => [$good->id, $bad->id, $unknown, $foreign->id]])
        ->assertOk()
        ->assertJsonPath('data.sent', 1)
        ->assertJsonPath('data.failed', 3);

    $results = collect($response->json('data.results'))->keyBy('invoice_id');

    expect($results[$good->id]['status'])->toBe('sent')
        ->and($results[$bad->id]['status'])->toBe('failed')
        ->and($results[$bad->id]['errors'][0])->toContain('billing address')
        ->and($results[$unknown]['errors'][0])->toBe('Invoice not found.')
        ->and($results[$foreign->id]['errors'][0])->toBe('Invoice not found.')
        ->and($good->sdiMetadata()->value('sdi_status'))->toBe('sending');
});

test('the bulk send validates its list', function (): void {
    Sanctum::actingAs($this->user);
    $api = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id]);

    $api->postJson('/api/v1/sales/invoices/send-sdi-bulk', ['invoice_ids' => []])->assertUnprocessable();
    $api->postJson('/api/v1/sales/invoices/send-sdi-bulk', ['invoice_ids' => array_map(fn (): string => (string) Str::uuid(), range(1, 101))])->assertUnprocessable();
});

// ------------------------------------------------------------ Language of the documents

test('the PDF follows the language of the customer, not the locale of the request', function (string $language, string $expected): void {
    $this->customer->update(['document_language' => $language]);
    App::setLocale($language === 'it' ? 'en' : 'it');

    $captured = null;
    app()->instance(PdfGenerator::class, new class($captured) implements PdfGenerator
    {
        public function __construct(private mixed &$captured) {}

        public function render(string $view, array $data = []): string
        {
            $this->captured = view($view, $data)->render();

            return 'pdf';
        }
    });
    app()->forgetInstance(DocumentPdfService::class);

    $locale = App::getLocale();
    app(DocumentPdfService::class)->render(issueInvoice()->refresh());

    expect($captured)->toContain($expected)
        ->and(App::getLocale())->toBe($locale);
})->with([
    'italian' => ['it', 'Imponibile'],
    'english' => ['en', 'Net total'],
]);

test('the document email follows the language of the customer', function (): void {
    Mail::fake();
    $this->customer->update(['document_language' => 'it']);
    App::setLocale('en');

    $email = app(QueueDocumentEmail::class)->execute(issueInvoice()->refresh());
    SendDocumentEmailJob::dispatchSync($email->id);

    expect($email->subject)->toStartWith('Fattura')
        ->and($email->refresh()->status)->toBe('sent');

    Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->locale === 'it');
});

test('the language of a contact is validated', function (): void {
    Sanctum::actingAs($this->user);

    $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
        ->postJson('/api/v1/crm/contacts', ['type' => 'customer', 'country' => 'IT', 'company_name' => 'X', 'document_language' => 'xx'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_language');
});
