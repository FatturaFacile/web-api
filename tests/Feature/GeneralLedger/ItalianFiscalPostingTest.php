<?php

declare(strict_types=1);

use App\Actions\Purchases\ReceivePassiveInvoice;
use App\Actions\Purchases\RecordSupplierPayment;
use App\Actions\Sales\RecordPayment;
use App\Actions\Sdi\CreateSelfInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Listeners\InvoiceAccountingListener;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\GeneralLedger\Services\WithholdingSettlementService;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano'],
    ]);
    $this->user = User::create(['name' => 'Ada', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('secret123')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897']);
    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Verdi', 'country' => 'DE', 'vat_number' => '123456789', 'fiscal_code' => 'VRDGPP80A01H501U']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant);
});

/**
 * @param  array<string, mixed>  $data
 */
function postedSale(array $data = [], float $rate = 22.0): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'contact_id' => test()->customer->id,
        'invoice_type' => 'standard',
        'date' => '2027-03-01',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => $rate]],
        ...$data,
    ]);
}

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function entryLines(string $documentId, string $source = 'sales_invoice'): array
{
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', $source)->where('source_document_id', $documentId)->firstOrFail();
    $debit = (float) $entry->lines()->sum('debit_amount');
    $credit = (float) $entry->lines()->sum('credit_amount');

    expect(round($debit, 2))->toBe(round($credit, 2));

    return DB::table('journal_lines as line')
        ->join('chart_of_accounts as account', 'account.id', '=', 'line.account_id')
        ->where('line.journal_entry_id', $entry->id)
        ->get(['account.account_code', 'line.debit_amount', 'line.credit_amount'])
        ->groupBy('account_code')
        ->map(fn ($lines): array => ['debit' => round((float) $lines->sum('debit_amount'), 2), 'credit' => round((float) $lines->sum('credit_amount'), 2)])
        ->all();
}

function accountBalance(string $code): float
{
    return round((float) DB::table('journal_lines as line')
        ->join('chart_of_accounts as account', 'account.id', '=', 'line.account_id')
        ->where('account.tenant_id', test()->tenant->id)
        ->where('account.account_code', $code)
        ->selectRaw('COALESCE(SUM(line.debit_amount - line.credit_amount), 0) as balance')
        ->value('balance'), 2);
}

test('a withholding on a sale becomes a credit towards the tax authority', function (): void {
    $invoice = postedSale(['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20]);
    $lines = entryLines($invoice->id);

    expect($lines['1100']['debit'])->toBe(1020.0)
        ->and($lines['1260']['debit'])->toBe(200.0)
        ->and($lines['4000']['credit'])->toBe(1000.0)
        ->and($lines['2200']['credit'])->toBe(220.0);
});

test('a stamp duty charged to the customer is owed to the tax authority, and a cost when it is not', function (bool $charged): void {
    $invoice = postedSale(['stamp_duty_applied' => true, 'stamp_duty_charged' => $charged], 0.0 + 22.0);
    $lines = entryLines($invoice->id);

    expect($lines['2240']['credit'])->toBe(2.0)
        ->and($lines['1100']['debit'])->toBe($charged ? 1222.0 : 1220.0);

    if (! $charged) {
        expect($lines['6810']['debit'])->toBe(2.0);
    }
})->with([
    'charged' => [true],
    'absorbed' => [false],
]);

test('the fund posts to income for the INPS rivalsa and to a liability for the others', function (string $type, string $account): void {
    $invoice = postedSale(['fund_enabled' => true, 'fund_type' => $type, 'fund_percent' => 4]);
    $lines = entryLines($invoice->id);

    expect($lines[$account]['credit'])->toBe(40.0)
        ->and($lines['2200']['credit'])->toBe(228.8)
        ->and($lines['1100']['debit'])->toBe(1268.8);
})->with([
    'rivalsa' => ['TC22', '4095'],
    'cassa avvocati' => ['TC01', '2245'],
]);

test('Enasarco reduces what the principal pays and is a cost of the agent', function (): void {
    EnasarcoParameter::create([
        'tenant_id' => $this->tenant->id, 'year' => 2027, 'agent_type' => 'single_mandate', 'rate_percent' => 10, 'agent_share_percent' => 50, 'annual_ceiling' => 40000, 'source' => 'Test figures',
    ]);

    $lines = entryLines(postedSale(['enasarco_enabled' => true, 'enasarco_agent_type' => 'single_mandate'])->id);

    expect($lines['6470']['debit'])->toBe(50.0)
        ->and($lines['1100']['debit'])->toBe(1170.0);
});

test('a split-payment invoice clears when the net is collected', function (): void {
    $invoice = postedSale(['split_payment' => true]);
    $lines = entryLines($invoice->id);

    expect($lines['1100']['debit'])->toBe(1000.0)
        ->and($lines['2205'])->toBe(['debit' => 220.0, 'credit' => 220.0])
        ->and($invoice->amountDue())->toBe('1000.00');

    app(RecordPayment::class)->execute($invoice, ['amount' => 1000, 'paid_at' => '2027-03-20']);

    expect($invoice->refresh()->payment_status)->toBe('paid')
        ->and(accountBalance('1100'))->toBe(0.0)
        ->and(accountBalance('2205'))->toBe(0.0);
});

test('collecting more than the net of a split-payment invoice is refused', function (): void {
    $invoice = postedSale(['split_payment' => true]);

    expect(fn () => app(RecordPayment::class)->execute($invoice, ['amount' => 1220, 'paid_at' => '2027-03-20']))->toThrow(ValidationException::class);
});

test('the split-payment VAT still reaches the VAT register as such', function (): void {
    $invoice = postedSale(['split_payment' => true]);

    $line = DB::table('journal_lines')->where('journal_entry_id', SalesInvoice::withoutGlobalScopes()->find($invoice->id)->gl_journal_entry_id)->where('vat_treatment', 'split_payment')->first();

    expect((float) $line->vat_tax_amount)->toBe(220.0)->and((float) $line->vat_base_amount)->toBe(1000.0);
});

test('a credit note on a split-payment invoice leaves receivable and VAT at zero', function (): void {
    $invoice = postedSale(['split_payment' => true]);

    app(InvoiceService::class)->createInvoice([
        'contact_id' => $this->customer->id, 'invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id, 'date' => '2027-03-02', 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ]);

    expect(accountBalance('1100'))->toBe(0.0)
        ->and(accountBalance('2205'))->toBe(0.0)
        ->and($invoice->refresh()->payment_status)->toBe('paid');
});

test('the posting is idempotent: the event replayed adds no entry', function (): void {
    $invoice = postedSale(['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20, 'split_payment' => true]);
    $listener = app(InvoiceAccountingListener::class);

    $listener->handle(new InvoicePostedEvent(
        invoiceId: $invoice->id, invoiceNumber: $invoice->invoice_number, customerId: $invoice->contact_id, date: '2027-03-01', dueDate: null, currency: 'EUR', exchangeRate: 1.0,
        totalNet: 1000.0, totalTax: 220.0, totalGross: 1220.0, revenueLines: [], taxLines: [], glJournalEntryId: null, tenantId: $this->tenant->id, companyId: $this->tenant->id,
    ));

    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $invoice->id)->count())->toBe(1);
});

test('the integration of a received invoice moves VAT only, on both sides', function (): void {
    $source = PassiveInvoice::create([
        'tenant_id' => $this->tenant->id, 'supplier_id' => $this->supplier->id, 'invoice_number' => 'DE-5', 'date' => '2027-02-20', 'total_amount' => 1000, 'total_net' => 1000,
        'total_tax' => 0, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid',
    ]);

    $invoice = app(CreateSelfInvoice::class)->execute($source, ['document_type' => 'TD17', 'date' => '2027-03-10', 'tax_rate' => 22]);
    $lines = entryLines($invoice->id);

    expect(array_keys($lines))->toEqualCanonicalizing(['1400', '2200'])
        ->and($lines['1400']['debit'])->toBe(220.0)
        ->and($lines['2200']['credit'])->toBe(220.0)
        ->and(accountBalance('1100'))->toBe(0.0);
});

test('self-consumption charges the VAT to a cost and a communication posts nothing', function (): void {
    $consumption = postedSale(['document_type' => 'TD27']);
    $lines = entryLines($consumption->id);

    expect($lines['6975']['debit'])->toBe(220.0)->and($lines['2200']['credit'])->toBe(220.0);

    $this->supplier->update(['country' => 'SM']);
    $communication = postedSale(['document_type' => 'TD28', 'contact_id' => $this->supplier->id, 'linked_document_number' => 'SM-1']);

    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $communication->id)->count())->toBe(0)
        ->and($communication->status)->toBe('posted');
});

test('withholding on a received invoice is a payable, and the supplier gets the net', function (): void {
    $invoice = passiveWithWithholding($this->tenant, $this->supplier);
    $lines = entryLines($invoice->id, 'vendor_invoice');

    expect($lines['2000']['credit'])->toBe(800.0)
        ->and($lines['2230']['credit'])->toBe(200.0)
        ->and($lines['6000']['debit'])->toBe(1000.0)
        ->and($invoice->amountDue())->toBe('800.00');

    app(RecordSupplierPayment::class)->execute($invoice, ['amount' => 800, 'paid_at' => '2027-03-20']);

    expect($invoice->refresh()->payment_status)->toBe('paid')->and(accountBalance('2000'))->toBe(0.0);
});

test('contributions on a received invoice go to their own payable', function (): void {
    $invoice = passiveWithWithholding($this->tenant, $this->supplier, [
        ['type' => 'RT01', 'amount' => '100.00', 'rate' => '10.00', 'reason' => 'R'],
        ['type' => 'RT04', 'amount' => '50.00', 'rate' => '5.00', 'reason' => 'R'],
    ]);
    $lines = entryLines($invoice->id, 'vendor_invoice');

    expect($lines['2230']['credit'])->toBe(100.0)
        ->and($lines['2235']['credit'])->toBe(50.0)
        ->and($lines['2000']['credit'])->toBe(850.0);
});

// ------------------------------------------------------------ Liquidazione ritenute

test('the month holds what was paid in it, and partial payments add up to the exact withholding', function (): void {
    $invoice = passiveWithWithholding($this->tenant, $this->supplier, [['type' => 'RT01', 'amount' => '100.01', 'rate' => '20.00', 'reason' => 'A']], 500.05);
    $payments = app(RecordSupplierPayment::class);

    $payments->execute($invoice, ['amount' => 133.33, 'paid_at' => '2027-03-05']);
    $payments->execute($invoice, ['amount' => 133.33, 'paid_at' => '2027-03-20']);
    $payments->execute($invoice, ['amount' => 133.38, 'paid_at' => '2027-04-02']);

    $service = app(WithholdingSettlementService::class);
    $march = $service->calculate($this->tenant->id, 2027, 3);
    $april = $service->calculate($this->tenant->id, 2027, 4);

    expect($march->lines)->toHaveCount(2)
        ->and(bcadd((string) $march->total_withheld, (string) $april->total_withheld, 2))->toBe('100.01')
        ->and($march->lines->first()->f24_code)->toBe('1040')
        ->and($march->due_date->toDateString())->toBe('2027-04-16');
});

test('the due date moves past a weekend', function (): void {
    // 16 May 2027 is a Sunday.
    $settlement = app(WithholdingSettlementService::class)->calculate($this->tenant->id, 2027, 4);

    expect($settlement->due_date->toDateString())->toBe('2027-05-17');
});

test('contributions are in the register but not in the F24', function (): void {
    $invoice = passiveWithWithholding($this->tenant, $this->supplier, [
        ['type' => 'RT01', 'amount' => '100.00', 'rate' => '10.00', 'reason' => 'R'],
        ['type' => 'RT04', 'amount' => '50.00', 'rate' => '5.00', 'reason' => 'R'],
    ]);
    app(RecordSupplierPayment::class)->execute($invoice, ['amount' => 850, 'paid_at' => '2027-03-20']);

    $service = app(WithholdingSettlementService::class);
    $settlement = $service->calculate($this->tenant->id, 2027, 3);
    $f24 = $service->f24($settlement);

    expect((string) $settlement->total_withheld)->toBe('150.00')
        ->and((string) $settlement->total_f24)->toBe('100.00')
        ->and($f24['lines'])->toBe([['tax_code' => '1040', 'reference_month' => '03', 'reference_year' => 2027, 'amount' => '100.00']]);
});

test('paying a settlement clears the payable, once, and freezes it', function (): void {
    $invoice = passiveWithWithholding($this->tenant, $this->supplier);
    app(RecordSupplierPayment::class)->execute($invoice, ['amount' => 800, 'paid_at' => '2027-03-20']);

    Sanctum::actingAs($this->user);
    $api = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id]);

    $id = $api->postJson('/api/v1/general-ledger/withholding-settlements/calculate', ['year' => 2027, 'month' => 3])->assertOk()->assertJsonPath('data.total_f24', '200.00')->json('data.id');
    $bank = app(LedgerAccountResolver::class)->bank($this->tenant->id);

    $api->getJson("/api/v1/general-ledger/withholding-settlements/{$id}/f24")->assertOk()->assertJsonPath('data.lines.0.tax_code', '1040');
    $api->postJson("/api/v1/general-ledger/withholding-settlements/{$id}/pay", ['payment_date' => '2027-04-16', 'bank_account_id' => $bank->id])->assertOk()->assertJsonPath('data.status', 'paid');

    expect(accountBalance('2230'))->toBe(0.0);

    $api->postJson("/api/v1/general-ledger/withholding-settlements/{$id}/pay", ['payment_date' => '2027-04-16'])->assertConflict();
    $api->postJson('/api/v1/general-ledger/withholding-settlements/calculate', ['year' => 2027, 'month' => 3])->assertConflict();
});

test('the settlement of another tenant cannot be reached', function (): void {
    $settlement = app(WithholdingSettlementService::class)->calculate($this->tenant->id, 2027, 3);
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other', 'country' => 'IT', 'default_currency' => 'EUR']);
    $outsider = User::create(['name' => 'Bob', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('secret123')]);
    $outsider->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    Sanctum::actingAs($outsider);

    $this->withHeaders(['X-Tenant-ID' => $other->id])->getJson("/api/v1/general-ledger/withholding-settlements/{$settlement->id}")->assertNotFound();
});

test('accounting access is required', function (): void {
    $sales = User::create(['name' => 'Sal', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('secret123')]);
    $sales->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);

    Sanctum::actingAs($sales);

    $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])->getJson('/api/v1/general-ledger/withholding-settlements')->assertForbidden();
});

test('the CU export holds the gross, the taxable and the withheld amount of the supplier', function (): void {
    $invoice = passiveWithWithholding($this->tenant, $this->supplier);
    app(RecordSupplierPayment::class)->execute($invoice, ['amount' => 800, 'paid_at' => '2027-03-20']);
    $service = app(WithholdingSettlementService::class);
    $service->calculate($this->tenant->id, 2027, 3);

    $file = $service->certificationExport($this->tenant->id, 2027);

    expect($file['file_name'])->toBe('cu-ritenute-2027.csv')
        ->and($file['content'])->toContain('Verdi;123456789;VRDGPP80A01H501U;A;1000.00;1000.00;200.00;0.00;0.00');
});

test('a received XML keeps its DatiRitenuta blocks', function (): void {
    $xml = <<<'XML'
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
<FatturaElettronicaHeader><CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567897</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>V</Denominazione></Anagrafica></DatiAnagrafici></CedentePrestatore><CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>00811720580</IdCodice></IdFiscaleIVA></DatiAnagrafici></CessionarioCommittente></FatturaElettronicaHeader>
<FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD06</TipoDocumento><Divisa>EUR</Divisa><Data>2027-03-01</Data><Numero>7</Numero><DatiRitenuta><TipoRitenuta>RT01</TipoRitenuta><ImportoRitenuta>200.00</ImportoRitenuta><AliquotaRitenuta>20.00</AliquotaRitenuta><CausalePagamento>A</CausalePagamento></DatiRitenuta><ImportoTotaleDocumento>1000.00</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali><DatiBeniServizi><DatiRiepilogo><AliquotaIVA>0.00</AliquotaIVA><Natura>N2.2</Natura><ImponibileImporto>1000.00</ImponibileImporto><Imposta>0.00</Imposta></DatiRiepilogo></DatiBeniServizi></FatturaElettronicaBody>
</p:FatturaElettronica>
XML;

    $received = app(ReceivePassiveInvoice::class)->execute($xml);
    $invoice = $received[0]['invoice']->refresh();

    expect((string) $invoice->withholding_tax_amount)->toBe('200.00')
        ->and($invoice->withholdings[0]['reason'])->toBe('A')
        ->and(entryLines($invoice->id, 'vendor_invoice')['2230']['credit'])->toBe(200.0);
});

/**
 * @param  list<array{type: string, amount: string, rate: string, reason: string}>|null  $withholdings
 */
function passiveWithWithholding(Tenant $tenant, Contact $supplier, ?array $withholdings = null, float $total = 1000.0): PassiveInvoice
{
    $withholdings ??= [['type' => 'RT01', 'amount' => '200.00', 'rate' => '20.00', 'reason' => 'A']];

    return app(PostPassiveInvoiceAction::class)->register([
        'tenant_id' => $tenant->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'P-'.Str::random(4),
        'date' => '2027-03-01',
        'total_amount' => $total,
        'total_net' => $total,
        'total_tax' => 0,
        'currency' => 'EUR',
        'document_type' => 'TD06',
        'payment_status' => 'unpaid',
        'withholdings' => $withholdings,
        'withholding_tax_amount' => array_sum(array_map(static fn (array $withholding): float => (float) $withholding['amount'], $withholdings)),
    ]);
}
