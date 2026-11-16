<?php

declare(strict_types=1);

use Modules\HumanResources\Services\Payroll\Rappen;
use Modules\HumanResources\Services\Payroll\RoundingPolicy;

test('amounts are rounded to the nearest 0.05 CHF', function (int $rappen, int $expected): void {
    expect(RoundingPolicy::roundToFiveRappen($rappen))->toBe($expected);
})->with([
    [12_341, 12_340],
    [12_342, 12_340],
    [12_343, 12_345],
    [12_347, 12_345],
    [12_348, 12_350],
    [0, 0],
    [-12_343, -12_345],
]);

test('rates in parts per million are applied with half-up rounding to the Rappen', function (): void {
    expect(RoundingPolicy::applyRate(800_000, 53_000))->toBe(42_400)
        ->and(RoundingPolicy::applyRate(535_500, 35_000))->toBe(18_743)
        ->and(RoundingPolicy::applyRate(1, 500_000))->toBe(1)
        ->and(RoundingPolicy::contribution(123_456, 53_000))->toBe(6_545);
});

test('annual amounts are pro-rated on a 360-day year', function (): void {
    expect(RoundingPolicy::annualToDays(14_820_000, 30))->toBe(1_235_000)
        ->and(RoundingPolicy::annualToDays(14_820_000, 15))->toBe(617_500)
        ->and(RoundingPolicy::divideRounded(5, 2))->toBe(3)
        ->and(RoundingPolicy::divideRounded(-5, 2))->toBe(-3);
});

test('decimal strings convert exactly to Rappen and parts per million without floating point drift', function (): void {
    expect(Rappen::fromDecimal('8000'))->toBe(800_000)
        ->and(Rappen::fromDecimal('0.1'))->toBe(10)
        ->and(Rappen::fromDecimal('2500.05'))->toBe(250_005)
        ->and(Rappen::fromDecimal('1.005'))->toBe(101)
        ->and(Rappen::fromDecimal(1234.56))->toBe(123_456)
        ->and(Rappen::fromDecimal(15))->toBe(1_500)
        ->and(Rappen::toDecimal(708_425))->toBe('7084.25')
        ->and(Rappen::toDecimal(-5))->toBe('-0.05')
        ->and(Rappen::percentToPpm('5.30'))->toBe(53_000)
        ->and(Rappen::percentToPpm('1.025'))->toBe(10_250)
        ->and(Rappen::percentToPpm(0.1))->toBe(1_000)
        ->and(Rappen::ppmToPercent(10_250))->toBe('1.025')
        ->and(Rappen::ppmToPercent(50_000))->toBe('5');
});

test('malformed decimal strings are rejected', function (): void {
    Rappen::fromDecimal('12,50');
})->throws(InvalidArgumentException::class);
