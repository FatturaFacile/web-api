<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/DssTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Acts as a member of the company with the role and, optionally, the permissions granted on the membership.
 *
 * @param  list<string>|null  $permissions
 */
function dssActAs(Tenant $tenant, string $role, ?array $permissions = null): void
{
    $user = tfrUserWithRole((string) $tenant->id, $role);

    if ($permissions !== null) {
        DB::table('tenant_user')->where('user_id', $user->id)->update(['permissions' => json_encode($permissions)]);
    }

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

/**
 * @param  array<string, mixed>  $query
 * @return list<array<string, mixed>>
 */
function dssKpis(array $overview): array
{
    return array_merge(...array_values($overview['scorecard']));
}

function dssKpi(array $overview, string $key): array
{
    return collect(dssKpis($overview))->firstWhere('key', $key);
}

test('the overview returns the balanced scorecard of the period with sparklines and working capital', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);

    $response = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk();
    $data = $response->json('data');

    expect(array_keys($data['scorecard']))->toEqual(['financial', 'customer', 'efficiency'])
        ->and($data['meta']['currency'])->toEqual('CHF')
        ->and($data['meta']['period'])->toEqual(['preset' => 'YTD', 'from' => '2026-01-01', 'to' => '2026-06-30'])
        ->and($data['meta']['comparison']['from'])->toEqual('2025-01-01')
        ->and(dssKpi($data, 'revenue')['value'])->toEqual(1000.0)
        ->and(dssKpi($data, 'gross_margin_pct')['value'])->toEqual(60.0)
        ->and(dssKpi($data, 'ebitda')['value'])->toEqual(400.0)
        ->and(dssKpi($data, 'net_cash_flow')['value'])->toEqual(500.0)
        ->and(dssKpi($data, 'operating_expense_ratio')['value'])->toEqual(20.0)
        // 300 of receivables on 1000 of revenue over 181 days; payables 400 over 400 of COGS
        ->and(dssKpi($data, 'average_dso')['value'])->toEqual(54.3)
        ->and($data['working_capital']['dpo_days'])->toEqual(181.0)
        ->and(dssKpi($data, 'cash_conversion_cycle')['value'])->toEqual(-126.7)
        ->and(dssKpi($data, 'revenue')['sparkline'])->toEqual([1000.0])
        // Nothing in the prior year: the comparison is zero, so the percentage is not defined.
        ->and(dssKpi($data, 'revenue')['comparison_value'])->toEqual(0.0)
        ->and(dssKpi($data, 'revenue')['delta'])->toEqual(1000.0)
        ->and(dssKpi($data, 'revenue')['delta_pct'])->toBeNull()
        ->and(dssKpi($data, 'on_time_delivery_pct')['available'])->toBeFalse();
});

test('the reporting currency switches every amount to EUR with the rate of the day', function (): void {
    $company = dssCompany();
    dssRate('CHF', 'EUR', 0.9);
    dssRate('EUR', 'CHF', 1.1, '2026-06-01');
    dssTradingMonth($company);

    $chf = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk()->json('data');
    $eur = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery(['currency' => 'EUR'])))->assertOk()->json('data');

    expect(dssKpi($chf, 'revenue')['value'])->toEqual(1000.0)
        ->and(dssKpi($eur, 'revenue')['value'])->toEqual(900.0)
        ->and(dssKpi($eur, 'ebitda')['value'])->toEqual(360.0)
        // Margins are currency neutral
        ->and(dssKpi($eur, 'gross_margin_pct')['value'])->toEqual(60.0)
        ->and($eur['meta']['fx']['CHF_EUR'])->toEqual(0.9)
        ->and($eur['meta']['fx']['EUR_CHF'])->toEqual(1.1);
});

test('the period presets and the custom range select the ledger movements by day', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);

    $revenue = fn (array $query): float => (float) dssKpi($this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery($query)))->assertOk()->json('data'), 'revenue')['value'];

    expect($revenue(['period_preset' => 'MTD', 'as_of' => '2026-03-31']))->toEqual(1000.0)
        ->and($revenue(['period_preset' => 'MTD', 'as_of' => '2026-04-30']))->toEqual(0.0)
        ->and($revenue(['period_preset' => 'QTD', 'as_of' => '2026-05-15']))->toEqual(0.0)
        ->and($revenue(['period_preset' => 'QTD', 'as_of' => '2026-03-31']))->toEqual(1000.0)
        ->and($revenue(['period_preset' => 'L12M', 'as_of' => '2026-12-31']))->toEqual(1000.0)
        ->and($revenue(['period_preset' => 'CUSTOM', 'date_from' => '2026-03-11', 'date_to' => '2026-03-31']))->toEqual(0.0)
        ->and($revenue(['period_preset' => 'CUSTOM', 'date_from' => '2026-03-10', 'date_to' => '2026-03-10']))->toEqual(1000.0);
});

test('the prior period and the budget are comparison baselines of the scorecard', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    fiscalCalendar($company['tenant'], 2025);
    $a = $company['accounts'];
    dssEntry($company['tenant'], '2025-03-10', [['account' => $a['receivable'], 'debit' => 500.0], ['account' => $a['revenue'], 'credit' => 500.0]]);
    dssEntry($company['tenant'], '2025-03-12', [['account' => $a['cogs'], 'debit' => 250.0], ['account' => $a['payable'], 'credit' => 250.0]]);

    $year = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk()->json('data');

    expect(dssKpi($year, 'revenue')['comparison_value'])->toEqual(500.0)
        ->and(dssKpi($year, 'revenue')['delta_pct'])->toEqual(100.0)
        ->and(dssKpi($year, 'revenue')['favorable'])->toBeTrue()
        ->and(dssKpi($year, 'gross_margin_pct')['comparison_value'])->toEqual(50.0);

    $fiscalYear = DB::table('fiscal_years')->where('tenant_id', $company['tenant']->id)->where('year_code', 'FY2026')->value('id');
    $budget = (string) Str::uuid();
    DB::table('gl_budgets')->insert(['id' => $budget, 'tenant_id' => $company['tenant']->id, 'fiscal_year_id' => $fiscalYear, 'name' => 'Budget 2026', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
    dssDeferForeignKeys();

    foreach ([['revenue', 1200.0], ['cogs', 300.0], ['opex', 150.0]] as [$account, $amount]) {
        DB::table('gl_budget_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $company['tenant']->id, 'budget_id' => $budget, 'account_id' => $a[$account], 'period_number' => 3, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    $vsBudget = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery(['comparison_baseline' => 'BUDGET'])))->assertOk()->json('data');
    $overruns = collect($vsBudget['alerts'])->where('code', 'budget_overrun')->pluck('metric')->sort()->values()->all();

    expect($vsBudget['meta']['comparison']['budget_found'])->toBeTrue()
        ->and(dssKpi($vsBudget, 'revenue')['comparison_value'])->toEqual(1200.0)
        ->and(dssKpi($vsBudget, 'revenue')['delta'])->toEqual(-200.0)
        ->and(dssKpi($vsBudget, 'revenue')['favorable'])->toBeFalse()
        ->and(dssKpi($vsBudget, 'ebitda')['comparison_value'])->toEqual(750.0)
        // COGS 400 against 300 and OPEX 200 against 150 are both more than 10% over budget.
        ->and($overruns)->toEqual(['cogs', 'opex']);
});

test('single-customer concentration raises a critical alert and the customer indicators follow the invoices', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    $small = dssContact((string) $company['tenant']->id, 'Kleinkunde');
    dssInvoice($company['tenant'], $company['customer'], 900.0);
    dssInvoice($company['tenant'], $small, 100.0);

    $data = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk()->json('data');
    $alert = collect($data['alerts'])->firstWhere('code', 'customer_concentration');

    expect(dssKpi($data, 'customer_concentration_index')['value'])->toEqual(90.0)
        ->and($alert['severity'])->toEqual('CRITICAL')
        ->and($alert['context']['counterparty_id'])->toEqual($company['customer'])
        ->and($alert['context']['label'])->toEqual('Kunde AG')
        // No prior-year customers to retain.
        ->and(dssKpi($data, 'net_revenue_retention')['value'])->toBeNull();
});

test('the overview is cached per tenant and a mart update serves fresh figures', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    $other = dssCompany(actAs: false);
    dssTradingMonth($other);

    $first = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk()->json('data');
    DB::table('agg_executive_daily_snapshots')->where('tenant_id', $company['tenant']->id)->update(['revenue_chf' => 0]);
    $cached = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk()->json('data');

    expect(dssKpi($first, 'revenue')['value'])->toEqual(1000.0)
        ->and(dssKpi($cached, 'revenue')['value'])->toEqual(1000.0);

    $a = $company['accounts'];
    dssEntry($company['tenant'], '2026-03-25', [['account' => $a['receivable'], 'debit' => 500.0], ['account' => $a['revenue'], 'credit' => 500.0]]);
    $fresh = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk()->json('data');

    // The rebuilt day is read again (the 10th was zeroed by hand and is not rebuilt, the 25th is new).
    expect(dssKpi($fresh, 'revenue')['value'])->toEqual(500.0);

    // Another tenant's cache was not flushed by this tenant's update.
    $this->assertDatabaseHas('agg_executive_daily_snapshots', ['tenant_id' => $other['tenant']->id, 'revenue_chf' => 1000]);
});

test('the profitability matrix ranks customers by net profit with the Pareto cut-off and Whale Curve', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    $small = dssContact((string) $company['tenant']->id, 'Kleinkunde');
    dssInvoice($company['tenant'], $company['customer'], 900.0, attributes: ['due_date' => '2026-03-31', 'payment_status' => 'paid', 'total_paid' => 900, 'updated_at' => '2026-04-09 12:00:00']);
    dssInvoice($company['tenant'], $small, 100.0);

    $data = $this->getJson('/api/v1/analytics/dss/profitability-matrix?'.http_build_query(dssQuery(['dimension' => 'customer'])))->assertOk()->json('data');
    [$big, $minor] = $data['rows'];

    expect($data['dimension'])->toEqual('customer')
        ->and($big['key'])->toEqual($company['customer'])
        ->and($big['label'])->toEqual('Kunde AG')
        ->and($big['net_revenue'])->toEqual(900.0)
        // 90% of the 400 of COGS and 200 of opex that no customer is tagged with
        ->and($big['gross_margin'])->toEqual(540.0)
        ->and($big['ebitda'])->toEqual(360.0)
        // 9 days late on 900 at 6% a year
        ->and($big['payment_delay_cost'])->toEqual(round(900 * 9 / 365 * 0.06, 4))
        ->and($minor['ebitda'])->toEqual(40.0)
        ->and($data['totals']['ebitda'])->toEqual(400.0)
        ->and($data['pareto_cutoff_rank'])->toEqual(1)
        ->and($data['whale_curve'])->toHaveCount(2)
        ->and($data['whale_curve'][0]['rank'])->toEqual(1);
});

test('the profitability matrix works by cost center, legal entity, channel and product', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    $tenantId = (string) $company['tenant']->id;
    $product = tfrProduct($tenantId, 'WIDGET', 30.0, 100.0);
    $invoice = dssInvoice($company['tenant'], $company['customer'], 1000.0, attributes: ['project_id' => (string) Str::uuid()]);
    dssDeferForeignKeys();
    DB::table('invoice_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'invoice_id' => $invoice, 'product_id' => $product->id, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'line_total' => 1000, 'created_at' => now(), 'updated_at' => now()]);

    $rows = fn (string $dimension): array => $this->getJson('/api/v1/analytics/dss/profitability-matrix?'.http_build_query(dssQuery(['dimension' => $dimension])))->assertOk()->json('data.rows');

    $center = $rows('cost_center');
    $entity = $rows('legal_entity');
    $channel = $rows('channel');
    $byProduct = $rows('product');

    expect($center)->toHaveCount(1)
        ->and($center[0]['label'])->toEqual('Werkstatt')
        ->and($center[0]['net_revenue'])->toEqual(1000.0)
        ->and($center[0]['ebitda'])->toEqual(400.0)
        ->and($entity[0]['key'])->toEqual($tenantId)
        ->and($entity[0]['ebitda'])->toEqual(400.0)
        ->and($channel[0]['key'])->toEqual('project')
        ->and($byProduct[0]['label'])->toEqual('Product WIDGET')
        // Standard cost 10 x 30 against 400 of ledger COGS: the 100 of the pool follows revenue share
        ->and($byProduct[0]['cogs'])->toEqual(400.0);
});

test('the variance bridge closes from the prior year to the actual and splits the EBITDA delta', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    fiscalCalendar($company['tenant'], 2025);
    $a = $company['accounts'];
    dssEntry($company['tenant'], '2025-03-10', [['account' => $a['receivable'], 'debit' => 500.0], ['account' => $a['revenue'], 'credit' => 500.0]]);
    dssEntry($company['tenant'], '2025-03-12', [['account' => $a['cogs'], 'debit' => 250.0], ['account' => $a['payable'], 'credit' => 250.0]]);
    dssEntry($company['tenant'], '2025-03-15', [['account' => $a['opex'], 'debit' => 100.0], ['account' => $a['cash'], 'credit' => 100.0]]);

    $data = $this->getJson('/api/v1/analytics/dss/variance-bridge?'.http_build_query(dssQuery()))->assertOk()->json('data');
    $steps = collect($data['ebitda_bridge']);
    $values = $steps->pluck('value', 'key');
    $deltas = $steps->where('type', 'delta')->sum('value');

    expect($steps->pluck('key')->all())->toEqual(['baseline', 'volume', 'price', 'cogs', 'opex', 'fx', 'actual'])
        ->and($values['baseline'])->toEqual(150.0)
        ->and($values['actual'])->toEqual(400.0)
        ->and(round($values['baseline'] + $deltas, 4))->toEqual($values['actual'])
        // No invoice lines: one notional unit, so the whole revenue change is a price effect.
        ->and($data['volume_basis'])->toEqual('unit_fallback')
        ->and($values['volume'])->toEqual(0.0)
        ->and($values['price'])->toEqual(500.0)
        ->and($values['cogs'])->toEqual(-150.0)
        ->and($values['opex'])->toEqual(-100.0)
        ->and($data['summary']['delta'])->toEqual(250.0)
        ->and($data['summary']['delta_pct'])->toEqual(166.67)
        ->and($data['line_variances']['revenue'])->toEqual(['delta' => 500.0, 'delta_pct' => 100.0])
        ->and(collect($data['revenue_bridge'])->pluck('key')->all())->toEqual(['baseline', 'volume', 'price', 'fx', 'actual']);
});

test('the variance bridge separates the volume effect when invoice lines give the units', function (): void {
    $company = dssCompany();
    $tenantId = (string) $company['tenant']->id;
    fiscalCalendar($company['tenant'], 2025);
    $a = $company['accounts'];
    $product = tfrProduct($tenantId, 'UNIT', 5.0, 10.0);
    dssDeferForeignKeys();

    // Prior year: 100 units at 10 (revenue 1000, COGS 600). Actual: 120 units at 11 (revenue 1320, COGS 780), opex 200 -> 220.
    foreach ([['2025-03-10', 100, 10.0, 600.0, 200.0], ['2026-03-10', 120, 11.0, 780.0, 220.0]] as [$date, $units, $price, $cogs, $opex]) {
        $invoice = dssInvoice($company['tenant'], $company['customer'], $units * $price, $date);
        DB::table('invoice_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'invoice_id' => $invoice, 'product_id' => $product->id, 'description' => 'Unit', 'quantity' => $units, 'unit_price' => $price, 'line_total' => $units * $price, 'created_at' => now(), 'updated_at' => now()]);
        dssEntry($company['tenant'], $date, [['account' => $a['receivable'], 'debit' => $units * $price], ['account' => $a['revenue'], 'credit' => $units * $price]]);
        dssEntry($company['tenant'], $date, [['account' => $a['cogs'], 'debit' => $cogs], ['account' => $a['payable'], 'credit' => $cogs]]);
        dssEntry($company['tenant'], $date, [['account' => $a['opex'], 'debit' => $opex], ['account' => $a['cash'], 'credit' => $opex]]);
    }

    $data = $this->getJson('/api/v1/analytics/dss/variance-bridge?'.http_build_query(dssQuery()))->assertOk()->json('data');
    $values = collect($data['ebitda_bridge'])->pluck('value', 'key');

    // Baseline EBITDA 1000 - 600 - 200 = 200; unit price 10, unit cost 6; actual 1320 - 780 - 220 = 320.
    expect($data['volume_basis'])->toEqual('invoice_items')
        ->and($values['baseline'])->toEqual(200.0)
        ->and($values['volume'])->toEqual(80.0)
        ->and($values['price'])->toEqual(120.0)
        ->and($values['cogs'])->toEqual(-60.0)
        ->and($values['opex'])->toEqual(-20.0)
        ->and($values['actual'])->toEqual(320.0);
});

test('the what-if simulation returns baseline, simulated and delta with the inputs for offline clients', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);

    $data = $this->postJson('/api/v1/analytics/dss/simulate', [...dssQuery(), 'scenario' => ['price_pct' => 10, 'cogs_pct' => 0, 'opex_pct' => 0, 'dso_days_delta' => 0, 'fx_shift_pct' => 0, 'fte_delta' => 0]])->assertOk()->json('data');

    expect($data['inputs']['revenue'])->toEqual(1000.0)
        ->and($data['inputs']['period_days'])->toEqual(181)
        ->and($data['baseline']['ebitda'])->toEqual(400.0)
        ->and($data['simulated']['revenue'])->toEqual(1100.0)
        ->and($data['simulated']['ebitda'])->toEqual(500.0)
        ->and($data['delta']['ebitda'])->toEqual(100.0)
        // 100 more EBITDA minus the receivables financing the extra 100 of sales (54.3 days of 100)
        ->and($data['simulated']['net_cash_flow'])->toEqualWithDelta(500.0 + 100.0 - 100.0 * 54.3 / 181, 0.01)
        ->and($data['simulated']['break_even_revenue'])->toEqual(round(200 / (700 / 1100), 4));

    $neutral = $this->postJson('/api/v1/analytics/dss/simulate', [...dssQuery(), 'scenario' => []])->assertUnprocessable();
    expect($neutral->json('errors'))->not->toBeNull();
});

test('the drill-down pages through the records behind a metric with links to the source', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);

    $revenue = $this->getJson('/api/v1/analytics/dss/drill-down?'.http_build_query(dssQuery(['metric' => 'revenue'])))->assertOk()->json('data');

    expect($revenue['data'])->toHaveCount(1)
        ->and($revenue['data'][0]['amount_reporting'])->toEqual(1000.0)
        ->and($revenue['data'][0]['posting_date'])->toEqual('2026-03-10')
        ->and($revenue['data'][0]['account_code'])->toEqual('90001')
        ->and($revenue['data'][0]['source']['type'])->toEqual('journal_entry')
        ->and($revenue['data'][0]['links']['source'])->toContain($revenue['data'][0]['source']['id'])
        ->and($revenue['total_amount'])->toEqual(1000.0);

    $cash = $this->getJson('/api/v1/analytics/dss/drill-down?'.http_build_query(dssQuery(['metric' => 'cash', 'per_page' => 1])))->assertOk()->json('data');

    expect($cash['pagination'])->toEqual(['page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2])
        ->and($cash['data'])->toHaveCount(1)
        ->and($cash['total_amount'])->toEqual(500.0);

    $page2 = $this->getJson('/api/v1/analytics/dss/drill-down?'.http_build_query(dssQuery(['metric' => 'cash', 'per_page' => 1, 'page' => 2])))->assertOk()->json('data');

    expect($page2['data'][0]['id'])->not->toEqual($cash['data'][0]['id']);

    $byCustomer = $this->getJson('/api/v1/analytics/dss/drill-down?'.http_build_query(dssQuery(['metric' => 'receivable', 'entity_type' => 'customer', 'entity_id' => $company['customer']])))->assertOk()->json('data');
    $byCenter = $this->getJson('/api/v1/analytics/dss/drill-down?'.http_build_query(dssQuery(['metric' => 'revenue', 'entity_type' => 'cost_center', 'entity_id' => $company['center']])))->assertOk()->json('data');
    $otherCenter = $this->getJson('/api/v1/analytics/dss/drill-down?'.http_build_query(dssQuery(['metric' => 'revenue', 'entity_type' => 'cost_center', 'entity_id' => (string) Str::uuid()])))->assertOk()->json('data');

    expect($byCustomer['data'])->toHaveCount(2)
        ->and($byCustomer['data'][0]['counterparty']['name'])->toEqual('Kunde AG')
        ->and($byCustomer['total_amount'])->toEqual(300.0)
        ->and($byCenter['data'])->toHaveCount(1)
        ->and($otherCenter['data'])->toEqual([]);
});

test('a cost center filter limits every figure to the lines of that center', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);

    $withCenter = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery(['cost_center_ids' => [$company['center']]])))->assertOk()->json('data');
    $otherCenter = (string) Str::uuid();
    DB::table('cost_centers')->insert(['id' => $otherCenter, 'tenant_id' => $company['tenant']->id, 'code' => 'CC2', 'name' => 'Büro', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $without = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery(['cost_center_ids' => [$otherCenter]])))->assertOk()->json('data');

    expect(dssKpi($withCenter, 'revenue')['value'])->toEqual(1000.0)
        ->and(dssKpi($withCenter, 'ebitda')['value'])->toEqual(1000.0)
        ->and(dssKpi($without, 'revenue')['value'])->toEqual(0.0);
});

test('the board pack is generated as a localized PDF and as an XLSX workbook', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);

    $pdf = $this->postJson('/api/v1/analytics/dss/export-board-pack', [...dssQuery(), 'format' => 'pdf', 'locale' => 'de-CH'])->assertOk();
    $xlsx = $this->postJson('/api/v1/analytics/dss/export-board-pack', [...dssQuery(['currency' => 'EUR']), 'format' => 'xlsx', 'locale' => 'it-IT'])->assertOk();

    expect($pdf->headers->get('content-type'))->toEqual('application/pdf')
        ->and($pdf->headers->get('content-disposition'))->toContain('board-pack-2026-06-30-chf.pdf')
        ->and(file_get_contents($pdf->baseResponse->getFile()->getPathname()))->toStartWith('%PDF')
        ->and($xlsx->headers->get('content-type'))->toEqual('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($xlsx->headers->get('content-disposition'))->toContain('board-pack-2026-06-30-eur.xlsx')
        ->and(file_get_contents($xlsx->baseResponse->getFile()->getPathname()))->toStartWith('PK');
});

test('the filter is validated: currency, preset, custom range, baseline, scope and the tenant of the ids', function (): void {
    dssCompany();
    $foreign = dssCompany(actAs: false);
    $get = fn (array $query) => $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query($query));

    $get(['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
    $get(['period_preset' => 'WEEKLY'])->assertUnprocessable()->assertJsonValidationErrors(['period_preset']);
    $get(['period_preset' => 'CUSTOM'])->assertUnprocessable()->assertJsonValidationErrors(['date_from', 'date_to']);
    $get(['period_preset' => 'CUSTOM', 'date_from' => '2026-05-01', 'date_to' => '2026-04-01'])->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
    $get(['comparison_baseline' => 'FORECAST'])->assertUnprocessable()->assertJsonValidationErrors(['comparison_baseline']);
    $get(['legal_entity_ids' => [(string) Str::uuid()]])->assertUnprocessable()->assertJsonValidationErrors(['legal_entity_ids.0']);
    $get(['cost_center_ids' => [$foreign['center']]])->assertUnprocessable()->assertJsonValidationErrors(['cost_center_ids.0']);

    $this->getJson('/api/v1/analytics/dss/profitability-matrix?dimension=planet')->assertUnprocessable()->assertJsonValidationErrors(['dimension']);
    $this->getJson('/api/v1/analytics/dss/drill-down')->assertUnprocessable()->assertJsonValidationErrors(['metric']);
    $this->getJson('/api/v1/analytics/dss/drill-down?metric=revenue&entity_type=customer')->assertUnprocessable()->assertJsonValidationErrors(['entity_id']);
    $this->postJson('/api/v1/analytics/dss/simulate', ['scenario' => ['price_pct' => 900]])->assertUnprocessable()->assertJsonValidationErrors(['scenario.price_pct']);
    $this->postJson('/api/v1/analytics/dss/export-board-pack', ['format' => 'docx'])->assertUnprocessable()->assertJsonValidationErrors(['format']);
});

test('the cockpit needs authentication and a tenant', function (): void {
    $this->getJson('/api/v1/analytics/dss/overview')->assertUnauthorized();
    $this->postJson('/api/v1/analytics/dss/simulate', ['scenario' => []])->assertUnauthorized();
});

test('role based access: finance sees and simulates, sales managers only read, members have no access', function (): void {
    $company = dssCompany(actAs: false);
    dssTradingMonth($company);
    $tenant = $company['tenant'];
    $scenario = [...dssQuery(), 'scenario' => ['price_pct' => 5]];

    foreach (['owner', 'admin', 'accountant'] as $role) {
        dssActAs($tenant, $role);
        $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk();
        $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertOk();
        $this->postJson('/api/v1/analytics/dss/export-board-pack', dssQuery())->assertOk();
    }

    dssActAs($tenant, 'accountant_readonly');
    $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk();
    $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertForbidden();

    dssActAs($tenant, 'sales_manager');
    $this->getJson('/api/v1/analytics/dss/profitability-matrix?'.http_build_query(dssQuery()))->assertOk();
    $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertForbidden();
    $this->postJson('/api/v1/analytics/dss/export-board-pack', dssQuery())->assertForbidden();

    dssActAs($tenant, 'member');
    foreach (['overview', 'profitability-matrix', 'variance-bridge', 'legal-entities'] as $path) {
        $this->getJson("/api/v1/analytics/dss/{$path}")->assertForbidden();
    }
    $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertForbidden();
});

test('granular abilities: bi.dss.view reads, bi.dss.simulate simulates, bi.dss.export exports, bi.dss.admin administers', function (): void {
    $company = dssCompany(actAs: false);
    dssTradingMonth($company);
    $tenant = $company['tenant'];
    $scenario = [...dssQuery(), 'scenario' => ['price_pct' => 5]];

    dssActAs($tenant, 'member', ['bi.dss.view']);
    $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk();
    $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertForbidden();
    $this->postJson('/api/v1/analytics/dss/export-board-pack', dssQuery())->assertForbidden();
    $this->getJson('/api/v1/analytics/dss/admin/reconciliation')->assertForbidden();

    dssActAs($tenant, 'member', ['bi.dss.simulate']);
    $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk();
    $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertOk();
    $this->postJson('/api/v1/analytics/dss/export-board-pack', dssQuery())->assertForbidden();

    dssActAs($tenant, 'member', ['bi.dss.export']);
    $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk();
    $this->postJson('/api/v1/analytics/dss/export-board-pack', dssQuery())->assertOk();
    $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertForbidden();

    dssActAs($tenant, 'member', ['bi.dss.admin']);
    $this->getJson('/api/v1/analytics/dss/admin/reconciliation')->assertOk();
    $this->postJson('/api/v1/analytics/dss/admin/consolidate')->assertOk();
    $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk();

    dssActAs($tenant, 'member', ['bi.dss.*']);
    $this->postJson('/api/v1/analytics/dss/simulate', $scenario)->assertOk();
    $this->postJson('/api/v1/analytics/dss/export-board-pack', dssQuery())->assertOk();
});

test('only owners and administrators reconcile and rebuild the mart, and the result is exact', function (): void {
    $company = dssCompany(actAs: false);
    dssTradingMonth($company);
    $tenant = $company['tenant'];

    dssActAs($tenant, 'accountant');
    $this->getJson('/api/v1/analytics/dss/admin/reconciliation')->assertForbidden();
    $this->postJson('/api/v1/analytics/dss/admin/consolidate')->assertForbidden();

    dssActAs($tenant, 'admin');
    $reconciliation = $this->getJson('/api/v1/analytics/dss/admin/reconciliation')->assertOk()->json('data');

    expect($reconciliation['variance'])->toEqual(0.0)
        ->and($reconciliation['mismatches'])->toEqual([]);

    DB::table('fact_financial_transactions')->where('tenant_id', $tenant->id)->delete();
    $drift = $this->getJson('/api/v1/analytics/dss/admin/reconciliation')->assertOk()->json('data');
    $rebuilt = $this->postJson('/api/v1/analytics/dss/admin/consolidate')->assertOk()->json('data');

    expect($drift['variance'])->toBeGreaterThan(0.0)
        ->and($rebuilt['documents_projected'])->toEqual(4)
        ->and($rebuilt['reconciliation']['variance'])->toEqual(0.0);
});

test('the legal entities of the filter bar are the ones of the tenant only', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    $other = dssCompany(actAs: false);
    dssTradingMonth($other);

    $entities = $this->getJson('/api/v1/analytics/dss/legal-entities')->assertOk()->json('data');

    expect($entities)->toHaveCount(1)
        ->and($entities[0]['id'])->toEqual((string) $company['tenant']->id)
        ->and($entities[0]['currency'])->toEqual('CHF');
});

test('a user of another tenant never reads this tenant mart', function (): void {
    $first = dssCompany(actAs: false);
    $second = dssCompany();
    dssTradingMonth($first);

    $data = $this->getJson('/api/v1/analytics/dss/overview?'.http_build_query(dssQuery()))->assertOk()->json('data');
    $drill = $this->getJson('/api/v1/analytics/dss/drill-down?'.http_build_query(dssQuery(['metric' => 'revenue'])))->assertOk()->json('data');

    expect(dssKpi($data, 'revenue')['value'])->toEqual(0.0)
        ->and($drill['data'])->toEqual([])
        ->and($second['tenant']->id)->not->toEqual($first['tenant']->id);
});
