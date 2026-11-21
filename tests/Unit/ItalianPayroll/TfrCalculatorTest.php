<?php

declare(strict_types=1);

use Modules\ItalianPayroll\Infrastructure\Services\ItalianTfrCalculator;

beforeEach(function (): void {
    $this->tfr = new ItalianTfrCalculator;
});

it('accrues the monthly TFR as gross over 13.5 minus the 0.50% INPS contribution', function (): void {
    // 2700 / 13.5 = 200.00; 0.5% of 2700 = 13.50 -> 186.50
    expect($this->tfr->monthlyProvision('2700.00'))->toBe('186.50');
    // 3000 / 13.5 = 222.2222...; 0.5% of 3000 = 15 -> 207.2222... rounded
    expect($this->tfr->monthlyProvision('3000.00'))->toBe('207.22');
    expect($this->tfr->monthlyProvision('0.00'))->toBe('0.00');
});

it('computes the revaluation rate as 1.5% plus 75% of the FOI change', function (): void {
    expect(trim($this->tfr->revaluationRate('2.0'), '0'))->toBe('.03');
    expect((float) $this->tfr->revaluationRate('4.0'))->toBe(0.045);
    expect((float) $this->tfr->revaluationRate('0'))->toBe(0.015);
});

it('taxes the revaluation at 17 percent', function (): void {
    $result = $this->tfr->netRevaluation('10000.00', '2.0');

    expect($result['gross'])->toBe('300.00')
        ->and($result['tax'])->toBe('51.00')
        ->and($result['net'])->toBe('249.00');
});

it('accrues one twelfth of the monthly pay for the 13th and 14th month', function (): void {
    expect($this->tfr->additionalMonthlyAccrual('2400.00'))->toBe('200.00');
    expect($this->tfr->additionalMonthlyAccrual('2500.00'))->toBe('208.33');
    // A half month worked in the hiring month.
    expect($this->tfr->additionalMonthlyAccrual('2400.00', '0.5'))->toBe('100.00');
});
