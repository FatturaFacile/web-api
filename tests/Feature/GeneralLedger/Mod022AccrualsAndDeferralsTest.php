<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ItalianSdi\FatturaPaParser;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\Accrual;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\ScheduledReversalService;

uses(RefreshDatabase::class);

function mod022User(Tenant $tenant, string $role = 'accountant'): User
{
    $user = User::create([
        'name' => 'Mod022 '.$role,
        'email' => Str::uuid().'@example.com',
        'password' => 'secret123',
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid7(), 'role' => $role]);

    return $user;
}

function mod022ActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

function mod022Account(Tenant $tenant, string $code, string $name, string $type, string $normal): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'company_id' => null,
        'account_code' => $code,
        'name' => $name,
        'account_type' => $type,
        'account_class' => $type,
        'normal_balance' => $normal,
        'is_active' => true,
        'allows_manual_posting' => true,
    ]);
}

function mod022Period(Tenant $tenant, string $date): FiscalPeriod
{
    return FiscalPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->whereDate('start_date', '<=', $date)
        ->whereDate('end_date', '>=', $date)
        ->firstOrFail();
}

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Mod022 CH SA',
        'legal_name' => 'Mod022 CH SA',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    $this->otherTenant = Tenant::create([
        'name' => 'Other Tenant Srl',
        'legal_name' => 'Other Tenant Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->accountant = mod022User($this->tenant, 'accountant');
    $this->reader = mod022User($this->tenant, 'accountant_readonly');

    app(TenantContext::class)->setTenant($this->otherTenant);
    app(OpenFiscalYearAction::class)->execute((string) $this->otherTenant->id, 2026);
    $this->otherSupplier = Contact::create([
        'tenant_id' => $this->otherTenant->id,
        'type' => 'supplier',
        'country' => 'IT',
        'company_name' => 'Foreign Supplier',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);
    $this->fy2026 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);
    $this->fy2027 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2027);

    $this->receivables = mod022Account($this->tenant, '1100', 'Receivables', 'asset', 'debit');
    $this->receivables->update(['reconciliation_type' => 'customer']);
    $this->payables = mod022Account($this->tenant, '2000', 'Payables', 'liability', 'credit');
    $this->payables->update(['reconciliation_type' => 'supplier']);
    $this->inputVat = mod022Account($this->tenant, '1170', 'Input VAT', 'asset', 'debit');
    $this->outputVat = mod022Account($this->tenant, '2200', 'Output VAT', 'liability', 'credit');
    $this->bank = mod022Account($this->tenant, '1020', 'Bank CHF', 'asset', 'debit');

    $this->prepaidAccount = mod022Account($this->tenant, '1300', 'Transitorische Aktiven', 'asset', 'debit');
    $this->accruedRevAccount = mod022Account($this->tenant, '1301', 'Ratei Attivi', 'asset', 'debit');
    $this->deferredRevAccount = mod022Account($this->tenant, '2300', 'Transitorische Passiven', 'liability', 'credit');
    $this->accruedExpAccount = mod022Account($this->tenant, '2301', 'Ratei Passivi', 'liability', 'credit');

    $this->salesRevenue = mod022Account($this->tenant, '3200', 'Subscription Revenue', 'revenue', 'credit');
    $this->insuranceExpense = mod022Account($this->tenant, '6300', 'Insurance Expense', 'expense', 'debit');
    $this->interestExpense = mod022Account($this->tenant, '6900', 'Loan Interest Expense', 'expense', 'debit');

    $this->customer = Contact::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'customer',
        'country' => 'CH',
        'company_name' => 'Swiss Customer AG',
    ]);
    $this->supplier = Contact::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'supplier',
        'country' => 'CH',
        'company_name' => 'Helvetia Insurance AG',
    ]);

    mod022ActAs($this->accountant, $this->tenant);
    $this->putJson('/api/v1/general-ledger/settings', [
        'posting_accounts' => [
            PostingAccountRole::Receivables->value => (string) $this->receivables->id,
            PostingAccountRole::Payables->value => (string) $this->payables->id,
            PostingAccountRole::InputVat->value => (string) $this->inputVat->id,
            PostingAccountRole::OutputVat->value => (string) $this->outputVat->id,
            PostingAccountRole::SalesRevenue->value => (string) $this->salesRevenue->id,
            PostingAccountRole::PurchaseExpense->value => (string) $this->insuranceExpense->id,
            PostingAccountRole::PrepaidExpense->value => (string) $this->prepaidAccount->id,
            PostingAccountRole::DeferredRevenue->value => (string) $this->deferredRevAccount->id,
            PostingAccountRole::AccruedRevenue->value => (string) $this->accruedRevAccount->id,
            PostingAccountRole::AccruedExpense->value => (string) $this->accruedExpAccount->id,
        ],
    ])->assertOk();
});

afterEach(fn () => app(TenantContext::class)->clear());

test('validates and persists competence dates on sales invoices, passive invoice lines, FatturaPA XML, and journal lines', function (): void {
    mod022ActAs($this->accountant, $this->tenant);

    // 1. Sales invoice validation: single date without pair & inverted dates
    $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_type' => 'standard',
        'date' => '2026-10-01',
        'currency' => 'CHF',
        'jurisdiction' => 'CH',
        'items' => [[
            'description' => 'Annual SaaS',
            'quantity' => 1,
            'unit_price' => 1200,
            'tax_rate' => 8.1,
            'competence_start_date' => '2026-10-01',
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0.competence_end_date');

    $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_type' => 'standard',
        'date' => '2026-10-01',
        'currency' => 'CHF',
        'jurisdiction' => 'CH',
        'items' => [[
            'description' => 'Annual SaaS',
            'quantity' => 1,
            'unit_price' => 1200,
            'tax_rate' => 8.1,
            'competence_start_date' => '2027-09-30',
            'competence_end_date' => '2026-10-01',
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0.competence_end_date');

    // Valid sales invoice persists competence dates and exposes them on InvoiceItemResource
    $invResp = $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_type' => 'standard',
        'date' => '2026-10-01',
        'currency' => 'CHF',
        'jurisdiction' => 'CH',
        'items' => [[
            'description' => 'Annual SaaS Subscription',
            'quantity' => 1,
            'unit_price' => 1200,
            'tax_rate' => 0,
            'competence_start_date' => '2026-10-01',
            'competence_end_date' => '2027-09-30',
        ]],
    ])->assertCreated();

    expect($invResp->json('data.items.0.competence_start_date'))->toBe('2026-10-01')
        ->and($invResp->json('data.items.0.competence_end_date'))->toBe('2027-09-30');

    // 2. Passive invoice line competence PUT endpoint + validation
    $passive = PassiveInvoice::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->supplier->id,
        'invoice_number' => 'BILL-2026-10',
        'date' => '2026-10-01',
        'total_amount' => 1200,
        'total_net' => 1200,
        'total_tax' => 0,
        'currency' => 'CHF',
        'document_type' => 'TD01',
        'cost_account_id' => $this->insuranceExpense->id,
    ]);
    $passiveLine = PassiveInvoiceLine::create([
        'tenant_id' => $this->tenant->id,
        'passive_invoice_id' => $passive->id,
        'line_number' => 1,
        'description' => 'Annual Liability Insurance',
        'quantity' => 1,
        'unit_price' => 1200,
        'line_total' => 1200,
        'tax_rate' => 0,
        'cost_account_id' => $this->insuranceExpense->id,
    ]);

    $this->putJson("/api/v1/purchases/supplier-invoices/{$passive->id}/lines/{$passiveLine->id}/competence", [
        'competence_start_date' => '2026-10-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('competence_end_date');

    $this->putJson("/api/v1/purchases/supplier-invoices/{$passive->id}/lines/{$passiveLine->id}/competence", [
        'competence_start_date' => '2026-10-01',
        'competence_end_date' => '2027-09-30',
    ])->assertOk();

    expect($passiveLine->refresh()->competence_start_date?->toDateString())->toBe('2026-10-01')
        ->and($passiveLine->competence_end_date?->toDateString())->toBe('2027-09-30');

    // 3. FatturaPA XML <DataInizioPeriodo> & <DataFinePeriodo> extraction
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>12345678901</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>Fornitore XML Srl</Denominazione></Anagrafica></DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>99999999999</IdCodice></IdFiscaleIVA></DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>2026-11-01</Data><Numero>XML-01</Numero><ImportoTotaleDocumento>600.00</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi>
      <DettaglioLinee>
        <NumeroLinea>1</NumeroLinea>
        <Descrizione>Canone Software</Descrizione>
        <Quantita>1.00</Quantita>
        <PrezzoUnitario>600.00</PrezzoUnitario>
        <PrezzoTotale>600.00</PrezzoTotale>
        <AliquotaIVA>0.00</AliquotaIVA>
        <DataInizioPeriodo>2026-11-01</DataInizioPeriodo>
        <DataFinePeriodo>2027-04-30</DataFinePeriodo>
      </DettaglioLinee>
      <DatiRiepilogo><AliquotaIVA>0.00</AliquotaIVA><ImponibileImporto>600.00</ImponibileImporto><Imposta>0.00</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
    $parsed = app(FatturaPaParser::class)->parse($xml);
    expect($parsed['documents'][0]['lines'][0]['competence_start_date'])->toBe('2026-11-01')
        ->and($parsed['documents'][0]['lines'][0]['competence_end_date'])->toBe('2027-04-30');

    // 4. Manual journal / Prima Nota lines competence validation & persistence
    $this->postJson('/api/v1/general-ledger/journals', [
        'fiscal_period_id' => (string) mod022Period($this->tenant, '2027-01-15')->id,
        'posting_date' => '2027-01-15',
        'description' => 'Unpaired competence',
        'lines' => [
            ['account_id' => $this->interestExpense->id, 'debit_amount' => 184, 'competence_start_date' => '2026-07-01'],
            ['account_id' => $this->bank->id, 'credit_amount' => 184],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.competence_end_date');

    $jeResp = $this->postJson('/api/v1/general-ledger/journals', [
        'fiscal_period_id' => (string) mod022Period($this->tenant, '2027-07-01')->id,
        'posting_date' => '2027-07-01',
        'description' => 'Post-paid loan interest',
        'lines' => [
            [
                'account_id' => $this->interestExpense->id,
                'debit_amount' => 365,
                'competence_start_date' => '2026-07-01',
                'competence_end_date' => '2027-06-30',
            ],
            ['account_id' => $this->bank->id, 'credit_amount' => 365],
        ],
    ])->assertCreated();

    expect($jeResp->json('data.lines.0.competence_start_date'))->toBe('2026-07-01')
        ->and($jeResp->json('data.lines.0.competence_end_date'))->toBe('2027-06-30');
});

test('preview endpoint computes multi-period splits accurately', function (): void {
    mod022ActAs($this->accountant, $this->tenant);

    $this->postJson('/api/v1/general-ledger/accruals/preview', [
        'total_amount' => 1200,
        'competence_start_date' => '2026-10-01',
        'competence_end_date' => '2027-09-30',
        'document_date' => '2026-10-01',
        'direction' => 'cost',
        'fiscal_year_id' => (string) $this->fy2026->id,
    ])->assertOk()
        ->assertJsonPath('data.accrual_type', 'prepaid_expense')
        ->assertJsonPath('data.total_days', 365)
        ->assertJsonPath('data.period_days', 92)
        ->assertJsonPath('data.transitory_days', 273)
        ->assertJsonPath('data.recognized_amount', '302.47')
        ->assertJsonPath('data.transitory_amount', '897.53')
        ->assertJsonPath('data.cutoff_date', '2026-12-31')
        ->assertJsonPath('data.reversal_date', '2027-01-01');
});

test('year-end summary, 1-click 31/12 closing, 01/01 reversal symmetry, tenant isolation, and reconciliation CSV export work end-to-end', function (): void {
    mod022ActAs($this->accountant, $this->tenant);

    // 1. Risconto Passivo (Sales invoice billed in 2026 covering 2026-10-01..2027-09-30 -> 897.53 deferred)
    $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_type' => 'standard',
        'date' => '2026-10-01',
        'currency' => 'CHF',
        'jurisdiction' => 'CH',
        'items' => [[
            'description' => 'Annual SaaS License',
            'quantity' => 1,
            'unit_price' => 1200,
            'tax_rate' => 0,
            'competence_start_date' => '2026-10-01',
            'competence_end_date' => '2027-09-30',
        ]],
    ])->assertCreated();

    // 2. Risconto Attivo (Passive invoice billed in 2026 covering 2026-10-01..2027-09-30 -> 897.53 prepaid)
    $passive = PassiveInvoice::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->supplier->id,
        'invoice_number' => 'INS-2026-01',
        'date' => '2026-10-01',
        'total_amount' => 1200,
        'total_net' => 1200,
        'total_tax' => 0,
        'currency' => 'CHF',
        'document_type' => 'TD01',
        'cost_account_id' => $this->insuranceExpense->id,
    ]);
    PassiveInvoiceLine::create([
        'tenant_id' => $this->tenant->id,
        'passive_invoice_id' => $passive->id,
        'line_number' => 1,
        'description' => 'Annual Property Insurance',
        'quantity' => 1,
        'unit_price' => 1200,
        'line_total' => 1200,
        'tax_rate' => 0,
        'cost_account_id' => $this->insuranceExpense->id,
        'competence_start_date' => '2026-10-01',
        'competence_end_date' => '2027-09-30',
    ]);

    // 3. Rateo Passivo (Journal posted in 2027 for 2026-07-01..2027-06-30 -> 184.00 accrued in 2026)
    $this->postJson('/api/v1/general-ledger/journals', [
        'fiscal_period_id' => (string) mod022Period($this->tenant, '2027-07-01')->id,
        'posting_date' => '2027-07-01',
        'description' => 'Post-paid loan interest',
        'lines' => [
            [
                'account_id' => $this->interestExpense->id,
                'debit_amount' => 365,
                'competence_start_date' => '2026-07-01',
                'competence_end_date' => '2027-06-30',
            ],
            ['account_id' => $this->bank->id, 'credit_amount' => 365],
        ],
    ])->assertCreated();

    // 4. Rateo Attivo (Revenue billed in 2027 covering 2026-07-01..2027-06-30 -> 184.00 accrued in 2026)
    $salesInv2027 = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->customer->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-2027-RATEO',
        'date' => '2027-07-01',
        'currency' => 'CHF',
        'exchange_rate' => 1,
        'total_net' => 365,
        'total_tax' => 0,
        'total_gross' => 365,
        'status' => 'issued',
    ]);
    $salesInv2027->forceFill(['status' => 'issued'])->save();
    InvoiceItem::create([
        'tenant_id' => $this->tenant->id,
        'invoice_id' => $salesInv2027->id,
        'description' => 'Arrears Consulting Fee',
        'quantity' => 1,
        'unit_price' => 365,
        'tax_rate' => 0,
        'discount_percentage' => 0,
        'line_total' => 365,
        'revenue_account_id' => $this->salesRevenue->id,
        'competence_start_date' => '2026-07-01',
        'competence_end_date' => '2027-06-30',
    ]);

    // Noise from another tenant must never appear
    $otherPassive = PassiveInvoice::create([
        'tenant_id' => $this->otherTenant->id,
        'supplier_id' => $this->otherSupplier->id,
        'invoice_number' => 'OTHER-01',
        'date' => '2026-10-01',
        'total_amount' => 5000,
        'total_net' => 5000,
        'total_tax' => 0,
        'currency' => 'EUR',
        'document_type' => 'TD01',
    ]);
    PassiveInvoiceLine::create([
        'tenant_id' => $this->otherTenant->id,
        'passive_invoice_id' => $otherPassive->id,
        'line_number' => 1,
        'description' => 'Other Tenant Policy',
        'quantity' => 1,
        'unit_price' => 5000,
        'line_total' => 5000,
        'tax_rate' => 0,
        'competence_start_date' => '2026-10-01',
        'competence_end_date' => '2027-09-30',
    ]);

    // Summary check (all 4 classifications present)
    $summary = $this->getJson("/api/v1/general-ledger/accruals/summary?fiscal_year_id={$this->fy2026->id}")
        ->assertOk()
        ->json('data');

    expect($summary['items'])->toHaveCount(4)
        ->and($summary['kpis']['prepaid_expense_total'])->toBe('897.53')
        ->and($summary['kpis']['deferred_revenue_total'])->toBe('897.53')
        ->and($summary['kpis']['accrued_revenue_total'])->toBe('184.00')
        ->and($summary['kpis']['accrued_expense_total'])->toBe('184.00')
        ->and($summary['kpis']['pending_closing_count'])->toBe(4);

    // Filtering by accrual_type & search
    $this->getJson("/api/v1/general-ledger/accruals/summary?fiscal_year_id={$this->fy2026->id}&accrual_type=prepaid_expense&search=Property")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.description', 'Annual Property Insurance');

    // Read-only user cannot trigger closing entries
    mod022ActAs($this->reader, $this->tenant);
    $this->postJson('/api/v1/general-ledger/accruals/closing-entries', ['fiscal_year_id' => (string) $this->fy2026->id])
        ->assertForbidden();

    // 1-click closing entry generation (31/12/2026)
    mod022ActAs($this->accountant, $this->tenant);
    $closing = $this->postJson('/api/v1/general-ledger/accruals/closing-entries', [
        'fiscal_year_id' => (string) $this->fy2026->id,
    ])->assertOk()->json('data');

    expect($closing['cutoff_date'])->toBe('2026-12-31')
        ->and($closing['reversal_date'])->toBe('2027-01-01')
        ->and($closing['posted'])->toHaveCount(4)
        ->and($closing['failed'])->toBe([]);

    // Idempotency: re-running skips all 4 without duplicates
    $secondRun = $this->postJson('/api/v1/general-ledger/accruals/closing-entries', [
        'fiscal_year_id' => (string) $this->fy2026->id,
    ])->assertOk()->json('data');

    expect($secondRun['posted'])->toBe([])
        ->and($secondRun['skipped'])->toHaveCount(4);

    // Verify double-entry symmetry on the Risconto Attivo closing entry (Dr 1300 / Cr 6300 for 897.53)
    $prepaidAccrual = Accrual::where('tenant_id', $this->tenant->id)->where('accrual_type', 'prepaid_expense')->firstOrFail();
    $closingEntry = JournalEntry::with('lines')->findOrFail($prepaidAccrual->journal_entry_id);
    $linesByAccount = $closingEntry->lines->keyBy('account_id');
    expect($closingEntry->posting_date->toDateString())->toBe('2026-12-31')
        ->and($closingEntry->auto_reverse_on?->toDateString())->toBe('2027-01-01')
        ->and((string) $linesByAccount[$this->prepaidAccount->id]->debit_amount)->toBe('897.5300')
        ->and((string) $linesByAccount[$this->insuranceExpense->id]->credit_amount)->toBe('897.5300');

    // Generate 01/01/2027 reversal entries via API for first 2 items, and via ScheduledReversalService for the rest
    $firstTwoIds = Accrual::where('tenant_id', $this->tenant->id)->orderBy('id')->take(2)->pluck('id')->map(fn ($id) => (string) $id)->all();
    $revResp = $this->postJson('/api/v1/general-ledger/accruals/reversals', [
        'accrual_ids' => $firstTwoIds,
    ])->assertOk()->json('data');

    expect($revResp['reversed'])->toHaveCount(2)
        ->and($revResp['reversed'][0]['reversal_date'])->toBe('2027-01-01');

    // ScheduledReversalService reverses remaining due 01/01 entries and updates Accrual rows
    $scheduled = app(ScheduledReversalService::class)->processDue((string) $this->tenant->id, CarbonImmutable::parse('2027-01-01'), (string) $this->accountant->id);
    expect($scheduled['failed'])->toBe([]);

    // Verify transitory account 1300 net balance returns to zero (897.53 Dr on 31/12 - 897.53 Cr on 01/01)
    $prepaidAccrual->refresh();
    expect($prepaidAccrual->status)->toBe('reversed')
        ->and($prepaidAccrual->reversal_journal_entry_id)->not->toBeNull();

    $revEntry = JournalEntry::with('lines')->findOrFail($prepaidAccrual->reversal_journal_entry_id);
    expect($revEntry->posting_date->toDateString())->toBe('2027-01-01')
        ->and((string) $revEntry->lines->keyBy('account_id')[$this->prepaidAccount->id]->credit_amount)->toBe('897.5300')
        ->and((string) $revEntry->lines->keyBy('account_id')[$this->insuranceExpense->id]->debit_amount)->toBe('897.5300');

    // Summary now shows all 4 as reversal_posted
    $afterSummary = $this->getJson("/api/v1/general-ledger/accruals/summary?fiscal_year_id={$this->fy2026->id}&status=reversal_posted")
        ->assertOk()
        ->json('data');
    expect($afterSummary['items'])->toHaveCount(4)
        ->and($afterSummary['kpis']['reversal_posted_count'])->toBe(4)
        ->and($afterSummary['kpis']['pending_closing_count'])->toBe(0);

    // Auditor reconciliation CSV export
    $csvResponse = $this->get("/api/v1/general-ledger/accruals/reconciliation-export?fiscal_year_id={$this->fy2026->id}")
        ->assertOk();
    expect($csvResponse->headers->get('content-type'))->toContain('text/csv');
    $csv = $csvResponse->getContent();
    expect(str_starts_with((string) $csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('Annual Property Insurance')
        ->and($csv)->toContain('Annual SaaS License')
        ->and($csv)->toContain('897,53')
        ->and($csv)->toContain('184,00')
        ->and($csv)->toContain('reversal_posted');

    // Same worksheet as a native .xlsx workbook
    $xlsxResponse = $this->get("/api/v1/general-ledger/accruals/reconciliation-export?format=xlsx&fiscal_year_id={$this->fy2026->id}")
        ->assertOk();
    expect($xlsxResponse->headers->get('content-type'))->toContain('spreadsheetml.sheet')
        ->and($xlsxResponse->headers->get('content-disposition'))->toContain('.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'xl');
    file_put_contents($path, $xlsxResponse->getContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    @unlink($path);

    expect($sheet)->toContain('<row')
        ->and($sheet.$strings)->toContain('Annual Property Insurance')
        ->and($sheet)->toContain('897.53')
        ->and($sheet.$strings)->toContain('reversal_posted');

    $this->get('/api/v1/general-ledger/accruals/reconciliation-export?format=pdf')->assertStatus(422);
});
