<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceWithholding;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ItalianSdi\FatturaPaParser;
use App\Services\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Azienda Blu SRL', 'legal_name' => 'Azienda Blu SRL', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Marco Blu', 'email' => 'marco@blu.example', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    app(TenantContext::class)->setTenant($this->tenant);
});

function whReportApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

/**
 * A professional's invoice with rivalsa INPS 4% and ritenuta 20% on the fee only.
 *
 * @param  array<string, string>  $overrides
 */
function whReportXml(array $overrides = []): string
{
    $v = array_merge([
        'supplier_vat' => '01234567890',
        'supplier_name' => 'Avv. Mario Rossi',
        'supplier_cf' => 'RSSMRA85M01H501Q',
        'number' => 'P-1',
        'date' => '2026-01-20',
        'fee' => '1000.00',
        'fund' => '40.00',
        'taxable' => '1040.00',
        'tax' => '228.80',
        'total' => '1268.80',
        'withholding' => '200.00',
        'reason' => 'A',
    ], $overrides);

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>{$v['supplier_vat']}</IdCodice></IdFiscaleIVA>
      <CodiceFiscale>{$v['supplier_cf']}</CodiceFiscale>
      <Anagrafica><Denominazione>{$v['supplier_name']}</Denominazione></Anagrafica>
    </DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>00811720580</IdCodice></IdFiscaleIVA>
    </DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento>
      <TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>{$v['date']}</Data><Numero>{$v['number']}</Numero>
      <DatiRitenuta><TipoRitenuta>RT01</TipoRitenuta><ImportoRitenuta>{$v['withholding']}</ImportoRitenuta><AliquotaRitenuta>20.00</AliquotaRitenuta><CausalePagamento>{$v['reason']}</CausalePagamento></DatiRitenuta>
      <DatiCassaPrevidenziale><TipoCassa>TC22</TipoCassa><AlCassa>4.00</AlCassa><ImportoContributoCassa>{$v['fund']}</ImportoContributoCassa><ImponibileCassa>{$v['fee']}</ImponibileCassa><AliquotaIVA>22.00</AliquotaIVA></DatiCassaPrevidenziale>
      <ImportoTotaleDocumento>{$v['total']}</ImportoTotaleDocumento>
    </DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi>
      <DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>Parere legale</Descrizione><PrezzoUnitario>{$v['fee']}</PrezzoUnitario><PrezzoTotale>{$v['fee']}</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA><Ritenuta>SI</Ritenuta></DettaglioLinee>
      <DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>{$v['taxable']}</ImponibileImporto><Imposta>{$v['tax']}</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
}

function whReportReceive(string $xml): PassiveInvoice
{
    $response = whReportApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', [
        'file' => UploadedFile::fake()->createWithContent('IT01234567890_'.Str::random(5).'.xml', $xml),
    ])->assertCreated();

    app(TenantContext::class)->setTenant(test()->tenant);

    return PassiveInvoice::findOrFail($response->json('data.0.invoice.id'));
}

function whReportPay(PassiveInvoice $invoice, string $paidAt): void
{
    app(TenantContext::class)->setTenant(test()->tenant);

    SupplierPayment::create(['tenant_id' => test()->tenant->id, 'passive_invoice_id' => $invoice->id, 'amount' => '1068.80', 'paid_at' => $paidAt, 'type' => 'payment']);
    $invoice->update(['payment_status' => 'paid', 'total_paid' => '1068.80']);
}

test('the parser extracts DatiRitenuta with the implied taxable amount', function (): void {
    $document = (new FatturaPaParser)->parse(whReportXml())['documents'][0];

    expect($document['withholdings'])->toBe([
        ['type' => 'RT01', 'amount' => '200.00', 'rate' => '20.00', 'reason' => 'A', 'taxable' => '1000.00'],
    ]);
});

test('received invoices store their withholdings for the CU', function (): void {
    $invoice = whReportReceive(whReportXml());

    $withholding = PassiveInvoiceWithholding::where('passive_invoice_id', $invoice->id)->firstOrFail();

    expect($withholding->type)->toBe('RT01')
        ->and($withholding->reason)->toBe('A')
        ->and((string) $withholding->rate)->toBe('20.00')
        ->and((string) $withholding->taxable_amount)->toBe('1000.00')
        ->and((string) $withholding->amount)->toBe('200.00')
        ->and($withholding->withheld_year)->toBe(2026)
        ->and($withholding->supplier_id)->toBe($invoice->supplier_id);
});

test('the CU report groups paid withholdings by supplier and causale by payment year', function (): void {
    $january = whReportReceive(whReportXml());
    $february = whReportReceive(whReportXml(['number' => 'P-2', 'date' => '2026-02-20']));
    $december = whReportReceive(whReportXml(['number' => 'P-3', 'date' => '2026-12-20']));
    whReportReceive(whReportXml(['number' => 'P-4', 'date' => '2026-03-01'])); // not paid yet

    whReportPay($january, '2026-02-10');
    whReportPay($february, '2026-03-05');
    whReportPay($december, '2027-01-15'); // cash principle: belongs to the 2027 CU

    $report = whReportApi()->getJson('/api/v1/tax/withholdings/report?year=2026')->assertOk()->json('data');

    expect($report['year'])->toBe(2026)
        ->and($report['suppliers'])->toHaveCount(1)
        ->and($report['suppliers'][0])->toMatchArray([
            'name' => 'Avv. Mario Rossi',
            'fiscal_code' => 'RSSMRA85M01H501Q',
            'vat_number' => '01234567890',
            'reason' => 'A',
            'invoices' => 2,
            'gross_paid' => '2080.00',
            'taxable' => '2000.00',
            'withheld' => '400.00',
        ])
        ->and($report['totals'])->toBe(['gross_paid' => '2080.00', 'taxable' => '2000.00', 'withheld' => '400.00'])
        ->and($report['unpaid_withholdings'])->toHaveCount(1)
        ->and($report['unpaid_withholdings'][0]['invoice_number'])->toBe('P-4')
        ->and($report['unpaid_withholdings'][0]['withheld'])->toBe('200.00');

    // The totals reconcile with the stored withholdings of the invoices paid in 2026.
    $stored = PassiveInvoiceWithholding::whereIn('passive_invoice_id', [$january->id, $february->id])->get()
        ->sum(fn (PassiveInvoiceWithholding $row): int => Money::toCents((string) $row->amount));
    expect($stored)->toBe(40000);

    whReportApi()->getJson('/api/v1/tax/withholdings/report?year=2027')
        ->assertOk()
        ->assertJsonPath('data.suppliers.0.withheld', '200.00')
        ->assertJsonPath('data.unpaid_withholdings', []);
});

test('the CU report is empty for a year without withholdings and is isolated per tenant', function (): void {
    whReportPay(whReportReceive(whReportXml()), '2026-02-10');

    whReportApi()->getJson('/api/v1/tax/withholdings/report?year=2025')
        ->assertOk()
        ->assertJsonPath('data.suppliers', [])
        ->assertJsonPath('data.unpaid_withholdings', [])
        ->assertJsonPath('data.totals', ['gross_paid' => '0.00', 'taxable' => '0.00', 'withheld' => '0.00']);

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SRL', 'vat_number' => '02345678901', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $other->id)->getJson('/api/v1/tax/withholdings/report?year=2026')
        ->assertOk()
        ->assertJsonPath('data.suppliers', []);
});

test('the CU report downloads as CSV', function (): void {
    whReportPay(whReportReceive(whReportXml()), '2026-02-10');

    $response = whReportApi()->get('/api/v1/tax/withholdings/report?year=2026&format=csv')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->getContent())->toContain('Avv. Mario Rossi')
        ->and($response->getContent())->toContain('200.00');
});
