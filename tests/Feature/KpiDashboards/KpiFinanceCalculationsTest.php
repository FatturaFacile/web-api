<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/KpiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    kpiDeferForeignKeys();
});

/**
 * The chart of accounts and the postings of a small company in 2026 (and one revenue posting in 2025 for the comparison).
 *
 * @return array<string, mixed>
 */
function kpiLedgerCompany(): array
{
    $tenant = kpiTenant();
    $a = [
        'cash' => kpiAccount($tenant, '1000', 'asset', 'cash'),
        'inventory' => kpiAccount($tenant, '1200', 'asset', 'inventory'),
        'debt' => kpiAccount($tenant, '2500', 'liability', 'non_current_liability'),
        'revenue' => kpiAccount($tenant, '4000', 'revenue'),
        'cogs' => kpiAccount($tenant, '5000', 'cogs'),
        'opex' => kpiAccount($tenant, '6000', 'expense'),
        'depreciation' => kpiAccount($tenant, '6800', 'expense', 'non_cash_expense'),
        'equity' => kpiAccount($tenant, '2800', 'equity', 'equity'),
    ];

    kpiPost($tenant, '2026-01-02', [[$a['cash'], 30000, 0], [$a['debt'], 0, 30000]]);
    kpiPost($tenant, '2026-02-10', [[$a['cash'], 100000, 0], [$a['revenue'], 0, 100000]]);
    kpiPost($tenant, '2026-03-05', [[$a['cogs'], 40000, 0], [$a['cash'], 0, 40000]]);
    kpiPost($tenant, '2026-04-01', [[$a['opex'], 20000, 0], [$a['cash'], 0, 20000]]);
    kpiPost($tenant, '2026-04-02', [[$a['depreciation'], 5000, 0], [$a['cash'], 0, 5000]]);
    kpiPost($tenant, '2025-03-01', [[$a['cash'], 80000, 0], [$a['revenue'], 0, 80000]]);

    return ['tenant' => $tenant, 'accounts' => $a];
}

it('computes revenue, EBITDA and net financial position of the CEO cockpit from the posted ledger', function (): void {
    ['tenant' => $tenant] = kpiLedgerCompany();
    kpiActAs($tenant, 'owner');

    $response = kpiCockpit($this, 'ceo')->assertOk();

    $revenue = kpiWidget($response, 'revenue_ytd_vs_budget');
    expect($revenue['value'])->toEqual(100000.0)
        ->and($revenue['comparison']['prior_value'])->toEqual(80000.0)
        ->and($revenue['comparison']['delta'])->toEqual(20000.0)
        ->and($revenue['comparison']['delta_percent'])->toEqual(25.0)
        ->and($revenue['widget_type'])->toEqual('area_trend');

    // EBITDA = revenue − COGS − opex, with the depreciation (non-cash) below the line.
    $ebitda = kpiWidget($response, 'ebitda');
    expect($ebitda['value'])->toEqual(40000.0)
        ->and($ebitda['data']['margin_percent'])->toEqual(40.0)
        ->and(array_column($ebitda['data']['waterfall'], 'step'))->toEqual(['revenue', 'cogs', 'opex', 'ebitda'])
        ->and(array_column($ebitda['data']['waterfall'], 'amount'))->toEqual([100000.0, -40000.0, -20000.0, 40000.0]);

    // Cash 30,000 + 100,000 − 40,000 − 20,000 − 5,000 (+ 80,000 of 2025) = 145,000, debt 30,000.
    $pfn = kpiWidget($response, 'net_financial_position');
    expect($pfn['value'])->toEqual(115000.0)
        ->and($pfn['data']['breakdown']['liquidity'])->toEqual(145000.0)
        ->and($pfn['data']['breakdown']['financial_debt'])->toEqual(30000.0);
});

it('compares revenue with the approved budget by month', function (): void {
    ['tenant' => $tenant, 'accounts' => $a] = kpiLedgerCompany();
    $year = (string) Str::uuid();
    DB::table('fiscal_years')->insert(['id' => $year, 'tenant_id' => $tenant->id, 'year_code' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now()]);
    $budget = (string) Str::uuid();
    DB::table('gl_budgets')->insert(['id' => $budget, 'tenant_id' => $tenant->id, 'fiscal_year_id' => $year, 'name' => 'Budget 2026', 'version' => 1, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

    foreach ([1 => 30000, 2 => 60000] as $period => $amount) {
        DB::table('gl_budget_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'budget_id' => $budget, 'account_id' => $a['revenue'], 'period_number' => $period, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    kpiActAs($tenant, 'owner');
    $widget = kpiWidget(kpiCockpit($this, 'ceo', ['comparison' => 'budget'])->assertOk(), 'revenue_ytd_vs_budget');

    expect($widget['comparison']['prior_value'])->toEqual(90000.0)
        ->and($widget['data']['budget_value'])->toEqual(90000.0)
        ->and($widget['data']['variance'])->toEqual(10000.0)
        ->and($widget['data']['variance_percent'])->toEqual(11.11)
        ->and($widget['data']['series'][1])->toMatchArray(['period' => '2026-02', 'actual' => 100000.0, 'budget' => 60000.0]);
});

it('converts P&L flows at the average SNB rate and balance sheet stocks at the closing spot rate', function (): void {
    ['tenant' => $tenant] = kpiLedgerCompany();
    // EUR per CHF published on three dates of the period: average 1.00, closing 1.10.
    kpiRate('CHF', 'EUR', '2026-01-31', 0.90);
    kpiRate('CHF', 'EUR', '2026-03-31', 1.00);
    kpiRate('CHF', 'EUR', '2026-06-30', 1.10);
    kpiActAs($tenant, 'owner');

    $response = kpiCockpit($this, 'ceo', ['currency' => 'EUR'])->assertOk();

    $revenue = kpiWidget($response, 'revenue_ytd_vs_budget');
    $ebitda = kpiWidget($response, 'ebitda');
    $pfn = kpiWidget($response, 'net_financial_position');

    expect($revenue['fx_applied'])->toMatchArray(['kind' => 'average', 'rate' => 1.0, 'available' => true])
        ->and($revenue['value'])->toEqual(100000.0)
        ->and($ebitda['value'])->toEqual(40000.0)
        ->and($pfn['fx_applied'])->toMatchArray(['kind' => 'closing', 'rate' => 1.1])
        ->and($pfn['value'])->toEqual(126500.0)
        ->and($pfn['data']['breakdown']['liquidity'])->toEqual(159500.0)
        ->and($response->json('data.fx.average.rate'))->toEqual(1.0)
        ->and($response->json('data.fx.closing.as_of'))->toEqual('2026-06-30');
});

it('flags missing exchange rates instead of converting silently', function (): void {
    ['tenant' => $tenant] = kpiLedgerCompany();
    kpiActAs($tenant, 'owner');

    $widget = kpiWidget(kpiCockpit($this, 'ceo', ['currency' => 'EUR'])->assertOk(), 'revenue_ytd_vs_budget');

    expect($widget['fx_applied']['available'])->toBeFalse();
});

it('eliminates intercompany revenue, cost and balances between a Swiss parent and an Italian subsidiary', function (): void {
    $tenant = kpiTenant();
    $chf = kpiCompany($tenant, 'CH01', 'CHF');
    $eur = kpiCompany($tenant, 'IT01', 'EUR');
    $a = ['revenue' => kpiAccount($tenant, '4000', 'revenue'), 'cogs' => kpiAccount($tenant, '5000', 'cogs'), 'cash' => kpiAccount($tenant, '1000', 'asset', 'cash')];

    // The Swiss parent sells 30,000 to the subsidiary and 50,000 outside; the subsidiary books the 30,000 as COGS and sells 80,000 outside.
    kpiPost($tenant, '2026-03-01', [[$a['cash'], 80000, 0], [$a['revenue'], 0, 80000]], $chf);
    kpiPost($tenant, '2026-03-02', [[$a['cogs'], 30000, 0], [$a['cash'], 0, 30000]], $eur);
    kpiPost($tenant, '2026-03-03', [[$a['cash'], 80000, 0], [$a['revenue'], 0, 80000]], $eur);
    DB::table('intercompany_transactions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'seller_company_id' => $chf, 'buyer_company_id' => $eur, 'reference' => 'IC-1', 'transaction_type' => 'goods', 'transaction_date' => '2026-03-01', 'currency' => 'CHF', 'net_amount' => 30000, 'tax_amount' => 0, 'gross_amount' => 30000, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
    // A draft intercompany transaction is never eliminated.
    DB::table('intercompany_transactions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'seller_company_id' => $chf, 'buyer_company_id' => $eur, 'reference' => 'IC-2', 'transaction_type' => 'goods', 'transaction_date' => '2026-03-01', 'currency' => 'CHF', 'net_amount' => 99999, 'tax_amount' => 0, 'gross_amount' => 99999, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    kpiActAs($tenant, 'owner');

    $single = kpiCockpit($this, 'ceo', ['company_ids' => [$chf]])->assertOk();
    $groupGross = kpiCockpit($this, 'ceo')->assertOk();
    $group = kpiCockpit($this, 'ceo', ['include_intercompany_eliminations' => '1'])->assertOk();

    // Single legal entity: its own revenue, no elimination (the counterparty is outside the scope).
    expect(kpiWidget($single, 'revenue_ytd_vs_budget')['value'])->toEqual(80000.0);
    // Group without elimination double counts; with elimination the 30,000 leaves revenue and COGS.
    expect(kpiWidget($groupGross, 'revenue_ytd_vs_budget')['value'])->toEqual(160000.0)
        ->and(kpiWidget($group, 'revenue_ytd_vs_budget')['value'])->toEqual(130000.0)
        ->and(kpiWidget($groupGross, 'ebitda')['value'])->toEqual(130000.0)
        // EBITDA is unchanged by the elimination: revenue and COGS fall by the same 30,000.
        ->and(kpiWidget($group, 'ebitda')['value'])->toEqual(130000.0)
        ->and(kpiWidget($group, 'ebitda')['data']['waterfall'][0]['amount'])->toEqual(130000.0)
        ->and(kpiWidget($group, 'ebitda')['data']['waterfall'][1]['amount'])->toEqual(0.0);

    // Entity restricted to both companies explicitly behaves like the group.
    $both = kpiCockpit($this, 'ceo', ['company_ids' => [$chf, $eur], 'include_intercompany_eliminations' => '1'])->assertOk();
    expect(kpiWidget($both, 'revenue_ytd_vs_budget')['value'])->toEqual(130000.0);
});

it('converts foreign currency intercompany eliminations at the rate of the transaction date', function (): void {
    $tenant = kpiTenant();
    $chf = kpiCompany($tenant, 'CH01', 'CHF');
    $eur = kpiCompany($tenant, 'IT01', 'EUR');
    $revenue = kpiAccount($tenant, '4000', 'revenue');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    kpiPost($tenant, '2026-03-01', [[$cash, 100000, 0], [$revenue, 0, 100000]], $chf);
    // 0.50 EUR per CHF: an intercompany invoice of 20,000 EUR is worth 40,000 CHF.
    kpiRate('CHF', 'EUR', '2026-03-01', 0.50);
    DB::table('intercompany_transactions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'seller_company_id' => $chf, 'buyer_company_id' => $eur, 'reference' => 'IC-1', 'transaction_type' => 'services', 'transaction_date' => '2026-03-01', 'currency' => 'EUR', 'net_amount' => 20000, 'tax_amount' => 0, 'gross_amount' => 20000, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
    kpiActAs($tenant, 'owner');

    $group = kpiCockpit($this, 'ceo', ['include_intercompany_eliminations' => '1'])->assertOk();

    expect(kpiWidget($group, 'revenue_ytd_vs_budget')['value'])->toEqual(60000.0);
});

it('computes DSO, DPO, working capital, aging and VAT of the CFO cockpit', function (): void {
    $tenant = kpiTenant();
    $customer = kpiContact($tenant, 'Kunde AG', 'CH');
    $supplier = kpiContact($tenant, 'Fornitore Srl', 'IT');
    $inventory = kpiAccount($tenant, '1200', 'asset', 'inventory');
    $equity = kpiAccount($tenant, '2800', 'equity', 'equity');
    kpiPost($tenant, '2026-01-15', [[$inventory, 10000, 0], [$equity, 0, 10000]]);

    // Period 2026-01-01..2026-06-30 = 181 days. Sales 90,000 net, of which 30,000 gross-open invoices.
    kpiDashInvoice($tenant, $customer, '2026-02-01', 60000, ['total_tax' => 0, 'payment_status' => 'paid', 'total_paid' => 60000]);
    kpiDashInvoice($tenant, $customer, '2026-05-01', 20000, ['total_tax' => 0, 'due_date' => '2026-06-15']);   // 15 days overdue: 1_30
    kpiDashInvoice($tenant, $customer, '2026-03-01', 10000, ['total_tax' => 0, 'due_date' => '2026-04-01']);   // 90 days overdue: 61_90
    kpiDashInvoice($tenant, $customer, '2026-06-01', 5000, ['total_tax' => 0, 'due_date' => '2026-07-15']);    // not due: current
    kpiDashInvoice($tenant, $customer, '2026-01-10', 4000, ['total_tax' => 0, 'due_date' => '2026-01-20']);    // over 90
    kpiDashInvoice($tenant, $customer, '2026-06-10', 1000, ['total_tax' => 0, 'invoice_type' => 'proforma']);  // never counts
    // Purchases 30,000 net, 12,000 open.
    kpiPassiveInvoice($tenant, $supplier, '2026-02-01', 18000, ['total_tax' => 0, 'payment_status' => 'paid', 'total_paid' => 18000]);
    kpiPassiveInvoice($tenant, $supplier, '2026-06-01', 12000, ['total_tax' => 0, 'due_date' => '2026-07-01']);
    kpiActAs($tenant, 'accountant');

    $response = kpiCockpit($this, 'cfo')->assertOk();

    // Open AR = 20,000 + 10,000 + 5,000 + 4,000 = 39,000 over sales of 99,000 (all non-proforma invoices) × 181 days.
    expect(kpiWidget($response, 'dso')['value'])->toEqual(round(39000 / 99000 * 181, 2))
        ->and(kpiWidget($response, 'dso')['widget_type'])->toEqual('gauge')
        ->and(kpiWidget($response, 'dpo')['value'])->toEqual(round(12000 / 30000 * 181, 2));

    // NWC = AR 39,000 + inventory 10,000 − AP 12,000.
    $nwc = kpiWidget($response, 'net_working_capital');
    expect($nwc['value'])->toEqual(37000.0)
        ->and(array_column($nwc['data']['waterfall'], 'amount'))->toEqual([39000.0, 10000.0, -12000.0, 37000.0]);

    $aging = kpiWidget($response, 'ar_ap_aging')['data'];
    expect($aging['receivables'])->toEqual(['current' => 5000.0, '1_30' => 20000.0, '31_60' => 0.0, '61_90' => 10000.0, 'over_90' => 4000.0])
        ->and($aging['payables']['current'])->toEqual(12000.0);
});

it('splits the VAT liability by Swiss and Italian jurisdiction', function (): void {
    $tenant = kpiTenant();
    $customer = kpiContact($tenant, 'Kunde AG', 'CH');
    $swissSupplier = kpiContact($tenant, 'Lieferant AG', 'CH');
    $italianSupplier = kpiContact($tenant, 'Fornitore Srl', 'IT');
    kpiDashInvoice($tenant, $customer, '2026-03-01', 10000, ['total_tax' => 810, 'jurisdiction' => 'CH']);
    kpiDashInvoice($tenant, $customer, '2026-03-02', 10000, ['total_tax' => 2200, 'jurisdiction' => 'IT']);
    kpiDashInvoice($tenant, $customer, '2026-03-03', 1000, ['total_tax' => 81, 'jurisdiction' => 'CH', 'invoice_type' => 'credit_note']);
    kpiPassiveInvoice($tenant, $swissSupplier, '2026-03-04', 2000, ['total_tax' => 162]);
    kpiPassiveInvoice($tenant, $italianSupplier, '2026-03-05', 5000, ['total_tax' => 1100]);
    kpiActAs($tenant, 'accountant');

    $vat = kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'vat_liability');
    $byJurisdiction = collect($vat['data']['jurisdictions'])->keyBy('jurisdiction');

    expect($byJurisdiction['CH']['output_vat'])->toEqual(729.0)
        ->and($byJurisdiction['CH']['input_vat'])->toEqual(162.0)
        ->and($byJurisdiction['CH']['liability'])->toEqual(567.0)
        ->and($byJurisdiction['CH']['authority'])->toEqual('ESTV/AFC MWST')
        ->and($byJurisdiction['IT']['liability'])->toEqual(1100.0)
        ->and($byJurisdiction['IT']['authority'])->toEqual('Agenzia delle Entrate LIPE')
        ->and($vat['value'])->toEqual(1667.0);
});

it('computes the cash runway from treasury balances and the average monthly burn', function (): void {
    $tenant = kpiTenant();
    $revenue = kpiAccount($tenant, '4000', 'revenue');
    $opex = kpiAccount($tenant, '6000', 'expense');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    // Last three months to 2026-06-30: costs 90,000, revenue 30,000 → burn 20,000 per month.
    kpiPost($tenant, '2026-04-15', [[$opex, 90000, 0], [$cash, 0, 90000]]);
    kpiPost($tenant, '2026-05-15', [[$cash, 30000, 0], [$revenue, 0, 30000]]);
    $bank = (string) Str::uuid();
    DB::table('bank_account_balances')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'bank_account_id' => $bank, 'balance_date' => '2026-06-29', 'ledger_balance' => 100000, 'available_balance' => 100000, 'currency' => 'CHF', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_account_balances')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'bank_account_id' => $bank, 'balance_date' => '2026-06-20', 'ledger_balance' => 999999, 'available_balance' => 1, 'currency' => 'CHF', 'created_at' => now(), 'updated_at' => now()]);
    kpiActAs($tenant, 'accountant');

    $runway = kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway');

    expect($runway['value'])->toEqual(5.0)
        ->and($runway['data']['cash'])->toEqual(100000.0)
        ->and($runway['data']['monthly_burn'])->toEqual(20000.0)
        ->and($runway['data']['self_sustaining'])->toBeFalse();
});

it('reports a profitable company as not burning cash', function (): void {
    $tenant = kpiTenant();
    $revenue = kpiAccount($tenant, '4000', 'revenue');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    kpiPost($tenant, '2026-05-15', [[$cash, 30000, 0], [$revenue, 0, 30000]]);
    kpiActAs($tenant, 'accountant');

    $runway = kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway');

    expect($runway['value'])->toBeNull()->and($runway['data']['self_sustaining'])->toBeTrue();
});

it('filters ledger KPIs by cost center and legal entity', function (): void {
    $tenant = kpiTenant();
    $revenue = kpiAccount($tenant, '4000', 'revenue');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    $costCenterA = (string) Str::uuid();
    $costCenterB = (string) Str::uuid();
    kpiPost($tenant, '2026-03-01', [[$cash, 1000, 0], [$revenue, 0, 1000, $costCenterA]]);
    kpiPost($tenant, '2026-03-02', [[$cash, 4000, 0], [$revenue, 0, 4000, $costCenterB]]);
    kpiActAs($tenant, 'owner');

    expect(kpiWidget(kpiCockpit($this, 'ceo', ['cost_center_ids' => [$costCenterA]])->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(1000.0)
        ->and(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(5000.0);
});

it('never mixes the postings of another tenant into a cockpit', function (): void {
    ['tenant' => $mine] = kpiLedgerCompany();
    $other = kpiTenant();
    $revenue = kpiAccount($other, '4000', 'revenue');
    $cash = kpiAccount($other, '1000', 'asset', 'cash');
    kpiPost($other, '2026-03-01', [[$cash, 999999, 0], [$revenue, 0, 999999]]);
    kpiActAs($mine, 'owner');

    expect(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(100000.0);
});
