<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function passiveXml(string $number = 'F-1', string $type = 'TD01'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567890</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>Fornitore Srl</Denominazione></Anagrafica></DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>00811720580</IdCodice></IdFiscaleIVA></DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento><TipoDocumento>{$type}</TipoDocumento><Divisa>EUR</Divisa><Data>2027-03-01</Data><Numero>{$number}</Numero><ImportoTotaleDocumento>183.00</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi>
      <DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>Canone energia elettrica</Descrizione><Quantita>1.00</Quantita><UnitaMisura>NR</UnitaMisura><PrezzoUnitario>100.00</PrezzoUnitario><PrezzoTotale>100.00</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>
      <DettaglioLinee><NumeroLinea>2</NumeroLinea><Descrizione>Assistenza tecnica</Descrizione><PrezzoUnitario>50.00</PrezzoUnitario><PrezzoTotale>50.00</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>
      <DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>150.00</ImponibileImporto><Imposta>33.00</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
    <DatiPagamento><DettaglioPagamento><DataScadenzaPagamento>2027-04-30</DataScadenzaPagamento></DettaglioPagamento></DatiPagamento>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
}

function costAccount(Tenant $tenant, string $code): ChartOfAccount
{
    return ChartOfAccount::withoutGlobalScopes()->create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'account_code' => $code, 'name' => "Cost {$code}",
        'account_class' => 'expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'currency' => 'EUR', 'is_active' => true,
    ]);
}

function openPeriod(Tenant $tenant): void
{
    $year = FiscalYear::withoutGlobalScopes()->create(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'company_id' => $tenant->id, 'year_code' => 'FY2027', 'name' => 'FY2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'open']);
    FiscalPeriod::withoutGlobalScopes()->create(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'company_id' => $tenant->id, 'fiscal_year_id' => $year->id, 'period_number' => 1, 'period_code' => '2027-01', 'name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'open']);
}

function receivePassive(string $xml = ''): PassiveInvoice
{
    test()->post('/api/v1/purchases/passive-invoices/receive-xml', ['file' => UploadedFile::fake()->createWithContent('IT01234567890_00001.xml', $xml ?: passiveXml())], ['Accept' => 'application/json'])->assertCreated();

    return PassiveInvoice::latest('created_at')->firstOrFail();
}

function debitsByAccount(string $invoiceId): array
{
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_id', $invoiceId)->firstOrFail();

    return JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->where('debit_amount', '>', 0)->pluck('debit_amount', 'account_id')->map(static fn ($amount): float => (float) $amount)->all();
}

beforeEach(function (): void {
    $this->travelTo('2027-03-02 10:00:00');
    $this->tenant = complianceTenant();
    openPeriod($this->tenant);
    actingAsMember($this->tenant);
    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Fornitore Srl', 'vat_number' => '01234567890']);
});

test('the lines of the XML are stored with the document', function (): void {
    $invoice = receivePassive();

    $lines = $this->getJson("/api/v1/purchases/supplier-invoices/{$invoice->id}/lines")->assertOk()->json('data');

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toMatchArray(['line_number' => 1, 'description' => 'Canone energia elettrica', 'quantity' => '1.0000', 'unit_of_measure' => 'NR', 'line_total' => '100.00', 'tax_rate' => '22.00'])
        ->and($lines[1]['quantity'])->toBeNull();
});

test('without rules the whole net amount stays on the default account', function (): void {
    $invoice = receivePassive();

    expect(array_values(debitsByAccount($invoice->id)))->toContain(150.0)
        ->and($invoice->cost_account_id)->toBeNull();
});

test('rules send lines to their accounts: keyword first, then the generic rule', function (): void {
    $energy = costAccount($this->tenant, '6100');
    $services = costAccount($this->tenant, '6200');
    $this->postJson('/api/v1/purchases/cost-rules', ['supplier_id' => $this->supplier->id, 'cost_account_id' => $services->id])->assertCreated();
    $this->postJson('/api/v1/purchases/cost-rules', ['supplier_id' => $this->supplier->id, 'keyword' => 'ENERGIA', 'cost_account_id' => $energy->id])->assertCreated();

    $invoice = receivePassive();
    $debits = debitsByAccount($invoice->id);

    expect($debits[$energy->id])->toBe(100.0)
        ->and($debits[$services->id])->toBe(50.0)
        ->and($invoice->lines()->first()->cost_account_source)->toBe('rule');
});

test('a credit note posts its allocated cost on the opposite side', function (): void {
    $account = costAccount($this->tenant, '6100');
    $this->postJson('/api/v1/purchases/cost-rules', ['supplier_id' => $this->supplier->id, 'cost_account_id' => $account->id])->assertCreated();

    $invoice = receivePassive(passiveXml('NC-1', 'TD04'));
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_id', $invoice->id)->firstOrFail();

    expect((float) JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->where('account_id', $account->id)->value('credit_amount'))->toBe(150.0);
});

test('an override after the posting adds a reclassification and keeps the original entry', function (): void {
    $from = costAccount($this->tenant, '6100');
    $to = costAccount($this->tenant, '6300');
    $this->postJson('/api/v1/purchases/cost-rules', ['supplier_id' => $this->supplier->id, 'cost_account_id' => $from->id])->assertCreated();
    $invoice = receivePassive();
    $line = $invoice->lines()->first();

    $this->putJson("/api/v1/purchases/supplier-invoices/{$invoice->id}/cost-account", ['cost_account_id' => $to->id, 'line_ids' => [$line->id]])->assertOk();

    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'vendor_invoice_reclassification')->firstOrFail();
    $lines = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->get();

    expect((float) $lines->firstWhere('account_id', $to->id)->debit_amount)->toBe(100.0)
        ->and((float) $lines->firstWhere('account_id', $from->id)->credit_amount)->toBe(100.0)
        ->and($line->fresh()->cost_account_source)->toBe('manual')
        ->and(debitsByAccount($invoice->id)[$from->id])->toBe(150.0);
});

test('accounts, rules and documents of another tenant stay out of reach', function (): void {
    $invoice = receivePassive();
    $other = complianceTenant('Beta');
    $foreignAccount = costAccount($other, '6100');

    $this->postJson('/api/v1/purchases/cost-rules', ['supplier_id' => $this->supplier->id, 'cost_account_id' => $foreignAccount->id])->assertUnprocessable();
    $this->putJson("/api/v1/purchases/supplier-invoices/{$invoice->id}/cost-account", ['cost_account_id' => $foreignAccount->id])->assertUnprocessable();

    actingAsMember($other);
    $this->getJson("/api/v1/purchases/supplier-invoices/{$invoice->id}/lines")->assertNotFound();
    $this->putJson("/api/v1/purchases/supplier-invoices/{$invoice->id}/cost-account", ['cost_account_id' => $foreignAccount->id])->assertNotFound();
    expect($this->getJson('/api/v1/purchases/cost-rules')->json('data'))->toBe([]);
});

test('cost rules are closed to members and read-only for the read-only accountant', function (): void {
    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/purchases/cost-rules')->assertForbidden();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->getJson('/api/v1/purchases/cost-rules')->assertOk();
    $this->postJson('/api/v1/purchases/cost-rules', [])->assertForbidden();
});
