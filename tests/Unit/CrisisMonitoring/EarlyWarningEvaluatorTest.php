<?php

declare(strict_types=1);

use Modules\CrisisMonitoring\Domain\Ccii\EarlyWarningEvaluator;

/**
 * @param  array<string, string|null>  $overrides
 * @return array<string, string|null>
 */
function crisisFigures(array $overrides = []): array
{
    return array_merge([
        'net_equity' => '100000', 'legal_minimum_capital' => null, 'financial_expenses' => '1000', 'revenue' => '100000',
        'total_debt' => '200000', 'total_assets' => '500000', 'current_assets' => '150000', 'current_liabilities' => '100000',
        'cash_flow' => '25000', 'tax_social_debt' => '10000',
    ], $overrides);
}

/**
 * @return array<string, string>
 */
function crisisThresholds(): array
{
    return [
        'financial_charges_revenue_max' => '3.00', 'equity_total_debt_min' => '2.30', 'liquidity_ratio_min' => '108.00',
        'cash_flow_assets_min' => '0.50', 'tax_social_debt_assets_max' => '14.60',
    ];
}

/**
 * @return array{value: string|null, status: string, applicable: bool}
 */
function crisisDscr(string $value, string $status): array
{
    return ['value' => $value, 'status' => $status, 'applicable' => true];
}

it('returns the seven indicators, green when every figure is healthy', function (): void {
    $result = (new EarlyWarningEvaluator)->evaluate(crisisFigures(), crisisDscr('1.80', 'green'), crisisThresholds());

    expect($result['indicators'])->toHaveCount(7)
        ->and(array_column($result['indicators'], 'code'))->toBe(['net_equity', 'dscr_6m', 'financial_charges_revenue', 'equity_total_debt', 'liquidity_ratio', 'cash_flow_assets', 'tax_social_debt_assets'])
        ->and($result['overall_status'])->toBe('green')
        ->and($result['primary_triggers'])->toBe([])
        ->and($result['indicators'][2]['actual'])->toBe('1.00')
        ->and($result['indicators'][4]['actual'])->toBe('150.00');
});

it('computes the five ratios in percent with the variance from the ATECO threshold', function (): void {
    $result = (new EarlyWarningEvaluator)->evaluate(crisisFigures(['financial_expenses' => '5000']), crisisDscr('1.80', 'green'), crisisThresholds());
    $charges = $result['indicators'][2];

    expect($charges['actual'])->toBe('5.00')
        ->and($charges['threshold'])->toBe('3.00')
        ->and($charges['variance'])->toBe('2.00')
        ->and($charges['comparison'])->toBe('max')
        ->and($charges['status'])->toBe('red')
        ->and($result['indicators'][3]['comparison'])->toBe('min');
});

it('turns a ratio yellow inside the warning margin before it breaches', function (): void {
    $result = (new EarlyWarningEvaluator)->evaluate(crisisFigures(['financial_expenses' => '2500']), crisisDscr('1.80', 'green'), crisisThresholds());

    expect($result['indicators'][2]['status'])->toBe('yellow')->and($result['overall_status'])->toBe('yellow');
});

it('makes the DSCR below 1.0 a primary trigger and the overall status red', function (): void {
    $result = (new EarlyWarningEvaluator)->evaluate(crisisFigures(), crisisDscr('0.80', 'red'), crisisThresholds());

    expect($result['primary_triggers'])->toBe(['dscr_6m'])->and($result['overall_status'])->toBe('red');
});

it('makes a negative net equity or one below the legal minimum a primary trigger', function (): void {
    $negative = (new EarlyWarningEvaluator)->evaluate(crisisFigures(['net_equity' => '-1']), crisisDscr('1.80', 'green'), crisisThresholds());
    $belowMinimum = (new EarlyWarningEvaluator)->evaluate(crisisFigures(['net_equity' => '5000', 'legal_minimum_capital' => '10000']), crisisDscr('1.80', 'green'), crisisThresholds());
    $atMinimum = (new EarlyWarningEvaluator)->evaluate(crisisFigures(['net_equity' => '10000', 'legal_minimum_capital' => '10000']), crisisDscr('1.80', 'green'), crisisThresholds());

    expect($negative['primary_triggers'])->toBe(['net_equity'])
        ->and($negative['indicators'][0]['unit'])->toBe('amount')
        ->and($belowMinimum['primary_triggers'])->toBe(['net_equity'])
        ->and($atMinimum['primary_triggers'])->toBe([]);
});

it('is red when all five ratios breach even if the primary triggers are green', function (): void {
    $figures = crisisFigures([
        'financial_expenses' => '10000', 'net_equity' => '1000', 'total_debt' => '200000', 'current_assets' => '90000', 'cash_flow' => '100', 'tax_social_debt' => '100000',
    ]);
    $result = (new EarlyWarningEvaluator)->evaluate($figures, crisisDscr('1.80', 'green'), crisisThresholds());
    $ratios = array_slice($result['indicators'], 2);

    expect(array_unique(array_column($ratios, 'status')))->toBe(['red'])
        ->and($result['primary_triggers'])->toBe([])
        ->and($result['overall_status'])->toBe('red');
});

it('keeps the ratios green and without threshold when the sector is unknown or the denominator is zero', function (): void {
    $unknown = (new EarlyWarningEvaluator)->evaluate(crisisFigures(), crisisDscr('1.80', 'green'), null);
    $empty = (new EarlyWarningEvaluator)->evaluate(crisisFigures(['revenue' => '0', 'total_assets' => '0']), crisisDscr('1.80', 'green'), crisisThresholds());

    expect($unknown['indicators'][2]['threshold'])->toBeNull()
        ->and($unknown['overall_status'])->toBe('green')
        ->and($empty['indicators'][2]['actual'])->toBeNull()
        ->and($empty['indicators'][5]['actual'])->toBeNull()
        ->and($empty['overall_status'])->toBe('green');
});

it('does not alert on a DSCR that is not applicable', function (): void {
    $result = (new EarlyWarningEvaluator)->evaluate(crisisFigures(), ['value' => null, 'status' => 'green', 'applicable' => false], crisisThresholds());

    expect($result['indicators'][1]['actual'])->toBeNull()->and($result['indicators'][1]['status'])->toBe('green');
});
