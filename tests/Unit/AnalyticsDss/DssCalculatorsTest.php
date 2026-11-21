<?php

declare(strict_types=1);

use Modules\AnalyticsDss\Domain\Services\CashConversionCycleCalculator;
use Modules\AnalyticsDss\Domain\Services\ExecutiveAnomalyDetector;
use Modules\AnalyticsDss\Domain\Services\HolidayCalendar;
use Modules\AnalyticsDss\Domain\Services\ProfitabilityMatrixCalculator;
use Modules\AnalyticsDss\Domain\Services\VarianceDecompositionEngine;
use Modules\AnalyticsDss\Domain\Services\WhatIfScenarioSimulator;
use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;
use Modules\AnalyticsDss\Domain\ValueObjects\ProfitabilityInput;
use Modules\AnalyticsDss\Domain\ValueObjects\VarianceSide;

test('the profitability waterfall derives gross margin, contribution margins, EBITDA and net operating profit', function (): void {
    $result = (new ProfitabilityMatrixCalculator(costOfCapitalRate: 0.10))->calculate([
        new ProfitabilityInput('a', 'Alpha', grossRevenue: 1100.0, discounts: 100.0, cogs: 400.0, variableCosts: 100.0, directOpex: 150.0, allocatedOverhead: 50.0, depreciation: 20.0, averagePaymentDelayDays: 36.5),
    ]);

    $row = $result['rows'][0];

    expect($row['net_revenue'])->toBe(1000.0)
        ->and($row['gross_margin'])->toBe(600.0)
        ->and($row['gross_margin_pct'])->toBe(60.0)
        ->and($row['contribution_margin_1'])->toBe(500.0)
        ->and($row['contribution_margin_2'])->toBe(350.0)
        ->and($row['ebitda'])->toBe(300.0)
        // 1000 x 36.5/365 x 10% = 10 of financing cost of the late payments
        ->and($row['payment_delay_cost'])->toBe(10.0)
        ->and($row['net_operating_profit'])->toBe(270.0);
});

test('the Whale Curve ranks by net profit, peaks above the total and ends at 100%', function (): void {
    $result = (new ProfitabilityMatrixCalculator)->calculate([
        new ProfitabilityInput('loss', 'Loss maker', 500.0, 0.0, 450.0, 0.0, 200.0, 0.0),
        new ProfitabilityInput('big', 'Big', 1000.0, 0.0, 300.0, 0.0, 0.0, 0.0),
        new ProfitabilityInput('small', 'Small', 400.0, 0.0, 300.0, 0.0, 0.0, 0.0),
    ]);

    expect(array_column($result['rows'], 'key'))->toBe(['big', 'small', 'loss'])
        ->and(array_column($result['whale_curve'], 'cumulative_profit'))->toBe([700.0, 800.0, 650.0])
        ->and($result['whale_curve'][1]['cumulative_profit_pct'])->toBeGreaterThan(100.0)
        ->and($result['whale_curve'][2]['cumulative_profit_pct'])->toBe(100.0)
        // The first entity already holds 700 of the 800 positive profit (87.5% >= 80%).
        ->and($result['pareto_cutoff_rank'])->toBe(1)
        ->and($result['rows'][0]['in_pareto_core'])->toBeTrue()
        ->and($result['rows'][2]['is_value_destroying'])->toBeTrue()
        ->and($result['totals']['net_operating_profit'])->toBe(650.0);
});

test('the cash conversion cycle is DIO plus DSO minus DPO with burn rate and runway', function (): void {
    $result = (new CashConversionCycleCalculator)->calculate(
        receivables: 200.0, payables: 100.0, inventory: 150.0, revenue: 1200.0, cogs: 600.0, currentAssets: 500.0, currentLiabilities: 250.0,
        operatingCashFlow: -90.0, capex: 10.0, cashBalance: 300.0, netCashFlow: -90.0, days: 90,
    );

    expect($result['dso_days'])->toBe(15.0)
        ->and($result['dio_days'])->toBe(22.5)
        ->and($result['dpo_days'])->toBe(15.0)
        ->and($result['ccc_days'])->toBe(22.5)
        ->and($result['working_capital_ratio'])->toBe(2.0)
        ->and($result['free_cash_flow'])->toBe(-100.0)
        ->and($result['burn_rate_monthly'])->toBe(30.0)
        ->and($result['runway_months'])->toBe(10.0);
});

test('the variance bridge always closes and splits volume, price, cost, opex and FX', function (): void {
    $baseline = new VarianceSide(units: 100.0, unitPrice: 10.0, unitCost: 6.0, opex: 200.0);
    $actual = new VarianceSide(units: 120.0, unitPrice: 11.0, unitCost: 6.5, opex: 220.0);
    $result = (new VarianceDecompositionEngine)->decompose($baseline, $actual, fxEbitdaImpact: 15.0, fxRevenueImpact: 40.0);

    $steps = array_column($result['ebitda_bridge'], 'value', 'key');

    expect($steps['baseline'])->toBe(200.0)
        ->and($steps['volume'])->toBe(80.0)   // +20 units x (10 - 6)
        ->and($steps['price'])->toBe(120.0)   // +1 x 120 units
        ->and($steps['cogs'])->toBe(-60.0)    // -0.5 x 120 units
        ->and($steps['opex'])->toBe(-20.0)
        ->and($steps['fx'])->toBe(15.0)
        // 200 + 80 + 120 - 60 - 20 + 15
        ->and($steps['actual'])->toBe(335.0);

    $revenue = array_column($result['revenue_bridge'], 'value', 'key');

    expect($revenue['baseline'] + $revenue['volume'] + $revenue['price'] + $revenue['fx'])->toBe($revenue['actual'])
        ->and($result['summary']['delta'])->toBe(135.0)
        ->and($result['summary']['delta_pct'])->toBe(67.5);
});

test('the variance engine reports delta and delta percent against a baseline', function (): void {
    $engine = new VarianceDecompositionEngine;

    expect($engine->delta(200.0, 150.0))->toBe(['delta' => -50.0, 'delta_pct' => -25.0])
        ->and($engine->delta(0.0, 150.0)['delta_pct'])->toBeNull();
});

test('the what-if simulation applies the documented formulas to EBITDA, net cash and break-even', function (): void {
    $baseline = ['revenue' => 1000.0, 'cogs' => 400.0, 'opex' => 300.0, 'dso_days' => 36.5, 'net_cash_flow' => 100.0, 'period_days' => 365, 'fte_annual_cost' => 50.0, 'revenue_fx_share' => 0.5, 'cost_fx_share' => 0.0];
    $result = (new WhatIfScenarioSimulator)->simulate($baseline, ['price_pct' => 10.0, 'cogs_pct' => 5.0, 'opex_pct' => 0.0, 'dso_days_delta' => 10.0, 'fx_shift_pct' => 0.0, 'fte_delta' => 2.0]);

    // revenue 1100, cogs 420, opex 300 + 2 x 50 = 400 -> EBITDA 280 (baseline 300)
    expect($result['baseline']['ebitda'])->toBe(300.0)
        ->and($result['simulated']['revenue'])->toBe(1100.0)
        ->and($result['simulated']['cogs'])->toBe(420.0)
        ->and($result['simulated']['opex'])->toBe(400.0)
        ->and($result['simulated']['ebitda'])->toBe(280.0)
        // receivables 100 -> 1100 x 46.5/365 = 140.1370; cash = 100 + (280 - 300) - 40.1370
        ->and($result['simulated']['net_cash_flow'])->toBe(39.863)
        // break-even = 400 / ((1100 - 420) / 1100) = 646.9
        ->and($result['simulated']['break_even_revenue'])->toBe(647.0588)
        ->and($result['delta']['ebitda'])->toBe(-20.0);
});

test('an FX shift moves only the exposed share of revenue and cost, and a neutral scenario changes nothing', function (): void {
    $baseline = ['revenue' => 1000.0, 'cogs' => 400.0, 'opex' => 300.0, 'dso_days' => 30.0, 'net_cash_flow' => 0.0, 'period_days' => 365, 'revenue_fx_share' => 0.5, 'cost_fx_share' => 0.2];
    $simulator = new WhatIfScenarioSimulator;

    $fx = $simulator->simulate($baseline, ['fx_shift_pct' => 10.0]);
    $neutral = $simulator->simulate($baseline, []);

    // revenue x 1.05, cogs and opex x 1.02
    expect($fx['simulated']['revenue'])->toBe(1050.0)
        ->and($fx['simulated']['cogs'])->toBe(408.0)
        ->and($fx['simulated']['opex'])->toBe(306.0)
        ->and($neutral['delta']['ebitda'])->toBe(0.0)
        ->and($neutral['delta']['net_cash_flow'])->toBe(0.0);
});

test('the break-even is undefined while the contribution margin is not positive', function (): void {
    $result = (new WhatIfScenarioSimulator)->simulate(
        ['revenue' => 1000.0, 'cogs' => 900.0, 'opex' => 50.0, 'dso_days' => 0.0, 'net_cash_flow' => 0.0, 'period_days' => 365],
        ['cogs_pct' => 20.0],
    );

    expect($result['simulated']['break_even_revenue'])->toBeNull();
});

test('the anomaly detector flags margin erosion, DSO spikes, concentration, budget overruns and outliers', function (): void {
    $alerts = (new ExecutiveAnomalyDetector)->detect([
        'gross_margin_pct' => 40.0, 'prior_gross_margin_pct' => 50.0,
        'dso_days' => 60.0, 'prior_dso_days' => 40.0,
        'customer_revenue' => ['whale' => ['label' => 'Whale AG', 'revenue' => 800.0], 'minnow' => ['label' => 'Minnow', 'revenue' => 200.0]],
        'budget_lines' => ['opex' => ['budget' => 100.0, 'actual' => 125.0, 'is_cost' => true], 'cogs' => ['budget' => 100.0, 'actual' => 101.0, 'is_cost' => true]],
        'series' => ['revenue' => [100.0, 101.0, 99.0, 100.0, 102.0, 98.0, 250.0], 'flat' => [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0]],
    ]);

    $byCode = [];

    foreach ($alerts as $alert) {
        $byCode[$alert['code']][] = $alert;
    }

    expect($byCode['margin_erosion'][0]['severity'])->toBe('CRITICAL')
        ->and($byCode['dso_spike'][0]['severity'])->toBe('CRITICAL')
        ->and($byCode['customer_concentration'][0]['context']['counterparty_id'])->toBe('whale')
        ->and($byCode['budget_overrun'])->toHaveCount(1)
        ->and($byCode['budget_overrun'][0]['metric'])->toBe('opex')
        ->and($byCode['statistical_outlier'])->toHaveCount(1)
        ->and($byCode['statistical_outlier'][0]['metric'])->toBe('revenue')
        // Most severe first.
        ->and($alerts[0]['severity'])->toBe('CRITICAL');
});

test('a healthy business raises no alert', function (): void {
    $alerts = (new ExecutiveAnomalyDetector)->detect([
        'gross_margin_pct' => 50.0, 'prior_gross_margin_pct' => 49.0, 'dso_days' => 30.0, 'prior_dso_days' => 31.0,
        'customer_revenue' => ['a' => ['label' => 'A', 'revenue' => 100.0], 'b' => ['label' => 'B', 'revenue' => 100.0], 'c' => ['label' => 'C', 'revenue' => 100.0], 'd' => ['label' => 'D', 'revenue' => 100.0], 'e' => ['label' => 'E', 'revenue' => 100.0]],
        'budget_lines' => [], 'series' => [],
    ]);

    expect($alerts)->toBe([]);
});

test('the filter resolves the period presets and the comparison ranges', function (): void {
    $ytd = new DssFilter('t', periodPreset: 'YTD', comparisonBaseline: 'PRIOR_YEAR', asOf: '2026-05-17');
    $qtd = new DssFilter('t', periodPreset: 'QTD', comparisonBaseline: 'PRIOR_PERIOD', asOf: '2026-05-17');
    $l12m = new DssFilter('t', periodPreset: 'L12M', asOf: '2026-05-17');
    $mtd = new DssFilter('t', periodPreset: 'MTD', asOf: '2026-05-17');

    expect([$ytd->from, $ytd->to])->toBe(['2026-01-01', '2026-05-17'])
        ->and($ytd->comparisonRange())->toBe(['2025-01-01', '2025-05-17'])
        ->and([$qtd->from, $qtd->to])->toBe(['2026-04-01', '2026-05-17'])
        ->and($qtd->comparisonRange())->toBe(['2026-02-13', '2026-03-31'])
        ->and([$l12m->from, $l12m->to])->toBe(['2025-05-18', '2026-05-17'])
        ->and([$mtd->from, $mtd->to])->toBe(['2026-05-01', '2026-05-17'])
        ->and($ytd->withBaseline('BUDGET')->comparisonBaseline)->toBe('BUDGET');

    expect(fn () => new DssFilter('t', currency: 'USD'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new DssFilter('t', periodPreset: 'CUSTOM', customFrom: '2026-02-01', customTo: '2026-01-01'))->toThrow(InvalidArgumentException::class);
});

test('the holiday calendar knows the Swiss and Italian holidays and counts the working days', function (): void {
    $calendar = new HolidayCalendar;

    // Easter 2026 is April 5th: Good Friday April 3rd (CH), Easter Monday April 6th (CH and IT).
    expect($calendar->isSwissHoliday(new DateTimeImmutable('2026-04-03')))->toBeTrue()
        ->and($calendar->isItalianHoliday(new DateTimeImmutable('2026-04-03')))->toBeFalse()
        ->and($calendar->isItalianHoliday(new DateTimeImmutable('2026-04-06')))->toBeTrue()
        ->and($calendar->isItalianHoliday(new DateTimeImmutable('2026-04-25')))->toBeTrue()
        ->and($calendar->isSwissHoliday(new DateTimeImmutable('2026-08-01')))->toBeTrue()
        // April 2026: 22 weekdays minus Good Friday and Easter Monday.
        ->and($calendar->workingDaysInMonth(new DateTimeImmutable('2026-04-15')))->toBe(20);
});
