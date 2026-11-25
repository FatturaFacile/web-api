<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Support\RoyaltyCalculator;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frnFormula(array $overrides = []): array
{
    return [
        'royalty_tiers' => [['from' => 0, 'to' => null, 'rate' => 6]],
        'marketing_tiers' => [['from' => 0, 'to' => null, 'rate' => 2]],
        'minimum_guarantee' => 0,
        'startup_months' => 0,
        'startup_mode' => 'none',
        'startup_reduction_percent' => 0,
        'ramp_start_percent' => 0,
        'startup_applies_to_marketing' => false,
        ...$overrides,
    ];
}

it('charges a flat royalty and marketing fund on the net sales of the month', function (): void {
    $figures = RoyaltyCalculator::calculate(10000, frnFormula(), CarbonImmutable::parse('2020-01-01'), '2028-04');

    expect($figures['royalty_gross'])->toBe('600.00')
        ->and($figures['royalty_charged'])->toBe('600.00')
        ->and($figures['marketing_fund'])->toBe('200.00')
        ->and($figures['minimum_adjustment'])->toBe('0.00')
        ->and($figures['startup_factor'])->toBe('1.000000')
        ->and($figures['startup_month'])->toBe(100);
});

it('computes royalty and marketing fund on progressive brackets', function (): void {
    $formula = frnFormula([
        'royalty_tiers' => [['from' => 0, 'to' => 50000, 'rate' => 7], ['from' => 50000, 'to' => 100000, 'rate' => 6], ['from' => 100000, 'to' => null, 'rate' => 5]],
        'marketing_tiers' => [['from' => 0, 'to' => 100000, 'rate' => 2], ['from' => 100000, 'to' => null, 'rate' => 1.5]],
    ]);

    $figures = RoyaltyCalculator::calculate(120000, $formula, CarbonImmutable::parse('2020-01-01'), '2028-04');

    expect($figures['royalty_charged'])->toBe('7500.00')
        ->and($figures['marketing_fund'])->toBe('2300.00')
        ->and($figures['breakdown']['royalty_slices'])->toHaveCount(3)
        ->and($figures['breakdown']['marketing_slices'])->toHaveCount(2);
});

it('lifts the royalty to the monthly minimum guarantee and shows the difference as an adjustment', function (): void {
    $figures = RoyaltyCalculator::calculate(10000, frnFormula(['minimum_guarantee' => 1500]), CarbonImmutable::parse('2020-01-01'), '2028-04');

    expect($figures['royalty_after_startup'])->toBe('600.00')
        ->and($figures['minimum_guarantee_applied'])->toBe('1500.00')
        ->and($figures['royalty_charged'])->toBe('1500.00')
        ->and($figures['minimum_adjustment'])->toBe('900.00');
});

it('keeps the calculated royalty when it is above the minimum guarantee', function (): void {
    $figures = RoyaltyCalculator::calculate(50000, frnFormula(['minimum_guarantee' => 1500]), CarbonImmutable::parse('2020-01-01'), '2028-04');

    expect($figures['royalty_charged'])->toBe('3000.00')->and($figures['minimum_adjustment'])->toBe('0.00');
});

it('suspends royalty and minimum guarantee during a full start-up exemption, then applies them', function (): void {
    $formula = frnFormula(['royalty_tiers' => [['from' => 0, 'to' => null, 'rate' => 5]], 'marketing_tiers' => [['from' => 0, 'to' => null, 'rate' => 1.5]], 'minimum_guarantee' => 1200, 'startup_months' => 3, 'startup_mode' => 'full_exemption']);
    $opened = CarbonImmutable::parse('2028-01-05');

    $exempt = RoyaltyCalculator::calculate(10000, $formula, $opened, '2028-02');
    $after = RoyaltyCalculator::calculate(10000, $formula, $opened, '2028-04');

    expect($exempt['startup_month'])->toBe(2)
        ->and($exempt['startup_factor'])->toBe('0.000000')
        ->and($exempt['royalty_charged'])->toBe('0.00')
        ->and($exempt['minimum_guarantee_applied'])->toBe('0.00')
        ->and($exempt['marketing_fund'])->toBe('150.00')
        ->and($after['startup_month'])->toBe(4)
        ->and($after['royalty_charged'])->toBe('1200.00')
        ->and($after['minimum_adjustment'])->toBe('700.00');
});

it('reduces royalty and minimum guarantee by the reduction percent during a reduced-rate start-up', function (): void {
    $formula = frnFormula(['royalty_tiers' => [['from' => 0, 'to' => null, 'rate' => 6.5]], 'minimum_guarantee' => 2000, 'startup_months' => 4, 'startup_mode' => 'reduced_rate', 'startup_reduction_percent' => 50]);

    $figures = RoyaltyCalculator::calculate(20000, $formula, CarbonImmutable::parse('2028-01-02'), '2028-02');

    expect($figures['startup_factor'])->toBe('0.500000')
        ->and($figures['royalty_gross'])->toBe('1300.00')
        ->and($figures['royalty_after_startup'])->toBe('650.00')
        ->and($figures['minimum_guarantee_applied'])->toBe('1000.00')
        ->and($figures['royalty_charged'])->toBe('1000.00')
        ->and($figures['minimum_adjustment'])->toBe('350.00');
});

it('ramps the start-up factor linearly from the starting percentage towards one hundred', function (): void {
    $formula = frnFormula(['startup_months' => 6, 'startup_mode' => 'ramp_up', 'ramp_start_percent' => 25]);
    $opened = CarbonImmutable::parse('2028-01-10');

    $factors = array_map(
        static fn (string $period): string => RoyaltyCalculator::calculate(10000, $formula, $opened, $period)['startup_factor'],
        ['2028-01', '2028-03', '2028-06', '2028-07'],
    );

    expect($factors)->toBe(['0.250000', '0.500000', '0.875000', '1.000000']);
});

it('scales the minimum guarantee with the ramp factor', function (): void {
    $formula = frnFormula(['minimum_guarantee' => 1500, 'startup_months' => 6, 'startup_mode' => 'ramp_up', 'ramp_start_percent' => 25]);

    $figures = RoyaltyCalculator::calculate(10000, $formula, CarbonImmutable::parse('2028-01-10'), '2028-01');

    expect($figures['royalty_after_startup'])->toBe('150.00')
        ->and($figures['minimum_guarantee_applied'])->toBe('375.00')
        ->and($figures['royalty_charged'])->toBe('375.00')
        ->and($figures['minimum_adjustment'])->toBe('225.00');
});

it('applies the start-up factor to the marketing fund only when the formula says so', function (): void {
    $base = ['startup_months' => 3, 'startup_mode' => 'full_exemption'];
    $opened = CarbonImmutable::parse('2028-01-05');

    $spared = RoyaltyCalculator::calculate(10000, frnFormula($base), $opened, '2028-01');
    $exempt = RoyaltyCalculator::calculate(10000, frnFormula([...$base, 'startup_applies_to_marketing' => true]), $opened, '2028-01');

    expect($spared['marketing_fund'])->toBe('200.00')->and($exempt['marketing_fund'])->toBe('0.00');
});

it('counts the months of the store life from the opening month and ignores the period before it', function (): void {
    expect(RoyaltyCalculator::startupMonth(CarbonImmutable::parse('2028-01-31'), '2028-01'))->toBe(1)
        ->and(RoyaltyCalculator::startupMonth(CarbonImmutable::parse('2028-01-31'), '2028-02'))->toBe(2)
        ->and(RoyaltyCalculator::startupMonth(CarbonImmutable::parse('2028-06-15'), '2028-05'))->toBeNull()
        ->and(RoyaltyCalculator::startupMonth(null, '2028-05'))->toBeNull();
});

it('applies no relief to a store that has no opening date', function (): void {
    $formula = frnFormula(['startup_months' => 3, 'startup_mode' => 'full_exemption']);

    expect(RoyaltyCalculator::calculate(10000, $formula, null, '2028-01')['royalty_charged'])->toBe('600.00');
});

it('treats negative net sales as zero', function (): void {
    $figures = RoyaltyCalculator::calculate(-500, frnFormula(['minimum_guarantee' => 800]), CarbonImmutable::parse('2020-01-01'), '2028-04');

    expect($figures['net_sales'])->toBe('0.00')
        ->and($figures['royalty_gross'])->toBe('0.00')
        ->and($figures['royalty_charged'])->toBe('800.00');
});
