<?php

declare(strict_types=1);

use Modules\AnalyticalAccounting\Domain\Services\AllocationVectorSplitter;
use Modules\AnalyticalAccounting\Support\Money;

it('converts decimals to cents and back without float drift', function (): void {
    expect(Money::toCents('10.005'))->toBe(1001)
        ->and(Money::toCents('-10.005'))->toBe(-1001)
        ->and(Money::toCents(0.1 + 0.2))->toBe(30)
        ->and(Money::toCents(null))->toBe(0)
        ->and(Money::fromCents(-1234))->toBe('-12.3400');
});

it('allocates with the largest remainder method and ties go to the earliest key', function (): void {
    expect(Money::allocate(100, ['a' => '1', 'b' => '1', 'c' => '1']))->toBe(['a' => 34, 'b' => 33, 'c' => 33])
        ->and(Money::allocate(10, ['a' => '5', 'b' => '3', 'c' => '2']))->toBe(['a' => 5, 'b' => 3, 'c' => 2])
        ->and(Money::allocate(100, ['a' => '0', 'b' => '0']))->toBe(['a' => 0, 'b' => 0])
        ->and(Money::allocate(-100, ['a' => '1', 'b' => '1', 'c' => '1']))->toBe(['a' => -34, 'b' => -33, 'c' => -33]);
});

it('always adds up to the total whatever the weights', function (): void {
    $weights = ['a' => '17.3333', 'b' => '0.0001', 'c' => '99.9', 'd' => '3.14159', 'e' => '42'];

    foreach ([1, 7, 99, 100, 12345, 999999] as $total) {
        expect(array_sum(Money::allocate($total, $weights)))->toBe($total);
    }
});

it('rejects negative weights', function (): void {
    expect(fn () => Money::allocate(10, ['a' => '-1', 'b' => '2']))->toThrow(InvalidArgumentException::class);
});

it('computes percentages with two decimals and zero on an empty base', function (): void {
    expect(Money::percent('25', '200'))->toBe('12.50')->and(Money::percent('1', '0'))->toBe('0.00')->and(Money::percent('-1', '3'))->toBe('-33.33');
});

it('splits a line over vectors by percentage', function (): void {
    $vectors = [['cost_center_id' => 'A', 'percentage' => '50'], ['cost_center_id' => 'B', 'percentage' => '25'], ['cost_center_id' => 'C', 'percentage' => '25']];

    $split = (new AllocationVectorSplitter)->split(10001, $vectors);

    expect(array_column($split, 'amount_cents'))->toBe([5001, 2500, 2500])->and(array_sum(array_column($split, 'amount_cents')))->toBe(10001);
});

it('splits a line over vectors by exact amount', function (): void {
    $split = (new AllocationVectorSplitter)->split(10000, [['cost_center_id' => 'A', 'amount' => '60.00'], ['cost_center_id' => 'B', 'amount' => '40.00']]);

    expect(array_column($split, 'amount_cents'))->toBe([6000, 4000])->and($split[0]['split_percentage'])->toBe('60.0000');
});

it('rejects vectors that do not add up or mix modes', function (): void {
    $splitter = new AllocationVectorSplitter;

    expect(fn () => $splitter->split(10000, [['percentage' => '60'], ['percentage' => '30']]))->toThrow(InvalidArgumentException::class, '100')
        ->and(fn () => $splitter->split(10000, [['amount' => '60'], ['amount' => '30']]))->toThrow(InvalidArgumentException::class, 'line amount')
        ->and(fn () => $splitter->split(10000, [['amount' => '60'], ['percentage' => '40']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $splitter->split(10000, []))->toThrow(InvalidArgumentException::class);
});
