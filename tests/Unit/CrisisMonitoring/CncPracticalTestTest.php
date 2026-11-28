<?php

declare(strict_types=1);

use Modules\CrisisMonitoring\Domain\Ccii\CncPracticalTest;

/**
 * @return list<array{code: string, label_key: string, ratio_up_to: string|null, status: string}>
 */
function crisisTiers(): array
{
    return [
        ['code' => 'easily_achievable', 'label_key' => 'crisisApi.cnc.tier.easily_achievable', 'ratio_up_to' => '3.00', 'status' => 'green'],
        ['code' => 'achievable_with_measures', 'label_key' => 'crisisApi.cnc.tier.achievable_with_measures', 'ratio_up_to' => '6.00', 'status' => 'yellow'],
        ['code' => 'difficult', 'label_key' => 'crisisApi.cnc.tier.difficult', 'ratio_up_to' => '9.00', 'status' => 'red'],
        ['code' => 'not_feasible', 'label_key' => 'crisisApi.cnc.tier.not_feasible', 'ratio_up_to' => null, 'status' => 'red'],
    ];
}

it('classifies the debt over the annual free cash flow in the seeded tiers, bounds included', function (): void {
    $test = new CncPracticalTest;

    expect($test->evaluate('100000', '100000', crisisTiers(), [])['tier_code'])->toBe('easily_achievable')
        ->and($test->evaluate('300000', '100000', crisisTiers(), [])['tier_code'])->toBe('easily_achievable')
        ->and($test->evaluate('600000', '100000', crisisTiers(), [])['tier_code'])->toBe('achievable_with_measures')
        ->and($test->evaluate('600000', '100000', crisisTiers(), [])['status'])->toBe('yellow')
        ->and($test->evaluate('900000', '100000', crisisTiers(), [])['tier_code'])->toBe('difficult')
        ->and($test->evaluate('1000000', '100000', crisisTiers(), [])['tier_code'])->toBe('not_feasible')
        ->and($test->evaluate('1000000', '100000', crisisTiers(), [])['ratio'])->toBe('10.00');
});

it('is not feasible without a positive free cash flow and trivially feasible without debt', function (): void {
    $test = new CncPracticalTest;
    $noCash = $test->evaluate('500000', '-1000', crisisTiers(), []);
    $zeroCash = $test->evaluate('500000', '0', crisisTiers(), []);
    $noDebt = $test->evaluate('0', '-1000', crisisTiers(), []);

    expect($noCash['tier_code'])->toBe('not_feasible')->and($noCash['ratio'])->toBeNull()->and($noCash['status'])->toBe('red')
        ->and($zeroCash['tier_code'])->toBe('not_feasible')
        ->and($noDebt['tier_code'])->toBe('easily_achievable')->and($noDebt['ratio'])->toBeNull();
});

it('reports the checklist with its label keys and the three states', function (): void {
    $result = (new CncPracticalTest)->evaluate('100000', '50000', crisisTiers(), ['ateco_configured' => true, 'financial_debt_known' => null, 'cash_flow_positive' => false]);

    expect($result['checklist'])->toBe([
        ['code' => 'ateco_configured', 'label_key' => 'crisisApi.cnc.check.ateco_configured', 'passed' => true],
        ['code' => 'financial_debt_known', 'label_key' => 'crisisApi.cnc.check.financial_debt_known', 'passed' => null],
        ['code' => 'cash_flow_positive', 'label_key' => 'crisisApi.cnc.check.cash_flow_positive', 'passed' => false],
    ])->and($result['debt_to_restructure'])->toBe('100000.00')->and($result['annual_free_cash_flow'])->toBe('50000.00');
});
