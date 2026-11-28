<?php

declare(strict_types=1);

use Modules\DairyCheeseManufacturing\Domain\Milk\MilkConversion;
use Modules\DairyCheeseManufacturing\Domain\Milk\Period;
use Modules\DairyCheeseManufacturing\Domain\Weight\WeightLossCurveInterpolator;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;

describe('weight loss curve', function (): void {
    $points = [['age_months' => 12, 'loss_pct' => 6.5], ['age_months' => 24, 'loss_pct' => 10.5], ['age_months' => 36, 'loss_pct' => 13.5]];

    it('is zero at birth and rises linearly to the first point', function () use ($points): void {
        expect(WeightLossCurveInterpolator::lossPct($points, 0))->toBe(0.0)
            ->and(WeightLossCurveInterpolator::lossPct($points, 6))->toBe(3.25)
            ->and(WeightLossCurveInterpolator::lossPct($points, 12))->toBe(6.5);
    });

    it('interpolates between the points whatever their order', function () use ($points): void {
        expect(WeightLossCurveInterpolator::lossPct(array_reverse($points), 18))->toBe(8.5)
            ->and(WeightLossCurveInterpolator::lossPct($points, 30))->toBe(12.0);
    });

    it('stays flat after the last point and is zero without a curve', function () use ($points): void {
        expect(WeightLossCurveInterpolator::lossPct($points, 60))->toBe(13.5)
            ->and(WeightLossCurveInterpolator::lossPct([], 12))->toBe(0.0);
    });

    it('turns the loss into the theoretical weight of the wheel', function () use ($points): void {
        expect(WeightLossCurveInterpolator::theoreticalWeight(40.0, $points, 24))->toBe(35.8)
            ->and(WeightLossCurveInterpolator::theoreticalWeight(40.0, $points, 0))->toBe(40.0);
    });
});

describe('milk conversion', function (): void {
    it('converts liters to kg with the density and back', function (): void {
        expect(MilkConversion::kg(1000))->toBe(1032.0)
            ->and(MilkConversion::kg(1000, 1.03))->toBe(1030.0)
            ->and(MilkConversion::liters(1030, 1.03))->toBe(1000.0);
    });

    it('refuses negative quantities and a non positive density', function (): void {
        expect(fn () => MilkConversion::kg(-1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => MilkConversion::liters(10, 0))->toThrow(InvalidArgumentException::class);
    });
});

describe('period', function (): void {
    it('accepts a month and returns its first day', function (): void {
        expect(Period::isValid('2026-05'))->toBeTrue()
            ->and(Period::start('2026-05')->toDateString())->toBe('2026-05-01');
    });

    it('rejects anything that is not a month', function (): void {
        expect(Period::isValid('2026-13'))->toBeFalse()
            ->and(Period::isValid('2026-5'))->toBeFalse()
            ->and(fn () => Period::start('May 2026'))->toThrow(DairyCheeseException::class);
    });
});
