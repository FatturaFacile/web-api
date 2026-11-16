<?php

declare(strict_types=1);

use Modules\HumanResources\DTOs\Payroll\RateSet;
use Tests\Unit\HumanResources\Payroll\CalculatorFixtures;

test('a rate set document is parsed into exact integer units', function (): void {
    $rateSet = RateSet::fromArray(CalculatorFixtures::rates(), 'id-1', 'CH-2026', 2026);

    expect($rateSet->ahvEmployeePpm)->toBe(53_000)
        ->and($rateSet->alvCeilingAnnualRappen)->toBe(14_820_000)
        ->and($rateSet->bvgCoordinationDeductionRappen)->toBe(2_646_000)
        ->and($rateSet->bvgEmployerSharePpm)->toBe(500_000)
        ->and($rateSet->bvgCreditRateForAge(40))->toBe(100_000)
        ->and($rateSet->bvgCreditRateForAge(24))->toBe(0)
        ->and($rateSet->uvgClass('A1'))->toBe(['bu_ppm' => 1_000, 'nbu_ppm' => 12_000])
        ->and($rateSet->uvgClass('Z9'))->toBeNull()
        ->and($rateSet->fakRateForCanton('zh'))->toBe(10_250)
        ->and($rateSet->fakRateForCanton('GR'))->toBe(12_000);
});

test('missing or malformed rate set values are reported with their path', function (string $path, mixed $value, string $message): void {
    $rates = CalculatorFixtures::rates();
    data_set($rates, $path, $value);

    expect(fn () => RateSet::fromArray($rates))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing AHV rate' => ['ahv_iv_eo.employee', null, 'rates.ahv_iv_eo.employee'],
    'rate above 100%' => ['alv.employee', '101', 'rates.alv.employee'],
    'non numeric amount' => ['alv.ceiling_annual', 'lots', 'rates.alv.ceiling_annual'],
    'empty age credits' => ['bvg.age_credits', [], 'rates.bvg.age_credits'],
]);
