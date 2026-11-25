<?php

declare(strict_types=1);

use Modules\FranchiseNetwork\Domain\Support\TieredRateCalculator;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;

/**
 * The error code a callback is refused with, null when it passes.
 */
function frnRefusal(callable $callback): ?string
{
    try {
        $callback();
    } catch (FranchiseNetworkException $exception) {
        return $exception->errorCode();
    }

    return null;
}

const FRN_TIERS = [
    ['from' => 0, 'to' => 50000, 'rate' => 7],
    ['from' => 50000, 'to' => 100000, 'rate' => 6],
    ['from' => 100000, 'to' => null, 'rate' => 5],
];

it('applies each rate only to the slice of sales inside its bracket', function (): void {
    $result = TieredRateCalculator::calculate(120000, FRN_TIERS);

    expect($result['amount'])->toBe('7500.00')
        ->and($result['slices'])->toHaveCount(3)
        ->and($result['slices'][0])->toMatchArray(['base' => '50000.00', 'amount' => '3500.00', 'rate' => '7'])
        ->and($result['slices'][1])->toMatchArray(['base' => '50000.00', 'amount' => '3000.00'])
        ->and($result['slices'][2])->toMatchArray(['base' => '20000.00', 'amount' => '1000.00', 'to' => null]);
});

it('stays inside the first bracket for a small month', function (): void {
    $result = TieredRateCalculator::calculate('30000', FRN_TIERS);

    expect($result['amount'])->toBe('2100.00')->and($result['slices'])->toHaveCount(1);
});

it('does not open the next bracket for sales exactly at its lower limit', function (): void {
    $result = TieredRateCalculator::calculate(50000, FRN_TIERS);

    expect($result['amount'])->toBe('3500.00')->and($result['slices'])->toHaveCount(1);
});

it('charges nothing on zero sales', function (): void {
    $result = TieredRateCalculator::calculate(0, FRN_TIERS);

    expect($result['amount'])->toBe('0.00')->and($result['slices'])->toBe([]);
});

it('rounds the sum of the slices half away from zero', function (): void {
    $result = TieredRateCalculator::calculate('333.33', [['from' => 0, 'to' => null, 'rate' => 6.5]]);

    expect($result['amount'])->toBe('21.67');
});

it('accepts brackets that start at zero, are contiguous and end open', function (): void {
    expect(frnRefusal(fn () => TieredRateCalculator::assertValid(FRN_TIERS)))->toBeNull();
});

it('refuses brackets that do not start at zero or leave a gap', function (): void {
    expect(frnRefusal(fn () => TieredRateCalculator::assertValid([['from' => 10, 'to' => null, 'rate' => 5]])))->toBe('tiers_not_contiguous')
        ->and(frnRefusal(fn () => TieredRateCalculator::assertValid([['from' => 0, 'to' => 100, 'rate' => 5], ['from' => 200, 'to' => null, 'rate' => 4]])))->toBe('tiers_not_contiguous');
});

it('refuses a closed top bracket, an empty list, a rate out of range and limits that do not ascend', function (): void {
    expect(frnRefusal(fn () => TieredRateCalculator::assertValid([['from' => 0, 'to' => 100, 'rate' => 5]])))->toBe('tiers_top_must_be_open')
        ->and(frnRefusal(fn () => TieredRateCalculator::assertValid([])))->toBe('tiers_required')
        ->and(frnRefusal(fn () => TieredRateCalculator::assertValid([['from' => 0, 'to' => null, 'rate' => 120]])))->toBe('tier_rate_out_of_range')
        ->and(frnRefusal(fn () => TieredRateCalculator::assertValid([['from' => 0, 'to' => 0, 'rate' => 5], ['from' => 0, 'to' => null, 'rate' => 5]])))->toBe('tiers_not_ascending');
});
