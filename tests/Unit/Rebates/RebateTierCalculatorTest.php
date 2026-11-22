<?php

declare(strict_types=1);

use Modules\Rebates\Services\RebateTierCalculator;

/** @var list<array{threshold_amount: string, rate_percent: string, flat_amount?: string|null}> $scale */
$scale = [
    ['threshold_amount' => '10000.00', 'rate_percent' => '2'],
    ['threshold_amount' => '50000.00', 'rate_percent' => '4'],
];

it('adds the flat amount of the highest tier reached on a retroactive scale', function (): void {
    $tiers = [['threshold_amount' => '10000.00', 'rate_percent' => '1', 'flat_amount' => '500'], ['threshold_amount' => '30000.00', 'rate_percent' => '2', 'flat_amount' => '1000']];
    $calculator = new RebateTierCalculator;

    expect($calculator->rebateOn($tiers, '9999.99', 'retroactive')['rebate'])->toBe('0.00')
        ->and($calculator->rebateOn($tiers, '20000.00', 'retroactive')['rebate'])->toBe('700.00')
        ->and($calculator->rebateOn($tiers, '40000.00', 'retroactive')['rebate'])->toBe('1800.00');
});

it('adds the flat amounts of every tier reached on an incremental scale', function (): void {
    $tiers = [['threshold_amount' => '10000.00', 'rate_percent' => '1', 'flat_amount' => '500'], ['threshold_amount' => '30000.00', 'rate_percent' => '2', 'flat_amount' => '1000']];
    $calculator = new RebateTierCalculator;

    // 19 999.99 in the first band at 1% plus its 500; the second tier is not reached.
    expect($calculator->rebateOn($tiers, '29999.99', 'incremental')['rebate'])->toBe('700.00')
        // 20 000 at 1% + 500, then 10 000 at 2% + 1 000.
        ->and($calculator->rebateOn($tiers, '40000.00', 'incremental')['rebate'])->toBe('1900.00');
});

it('earns the fixed bonus only once the target is reached', function (): void {
    $calculator = new RebateTierCalculator;

    expect($calculator->fixedBonusOn('20000', '750', '19999.99'))->toBe('0.00')
        ->and($calculator->fixedBonusOn('20000', '750', '20000.00'))->toBe('750.00')
        ->and($calculator->fixedBonusOn('20000', '750', '0'))->toBe('0.00');
});

it('reports the position between two tiers with the distance and the projection', function () use ($scale): void {
    $progress = (new RebateTierCalculator)->progress($scale, '20000.00', 'retroactive');

    expect($progress['current_tier'])->toBe(['threshold_amount' => '10000.00', 'rate_percent' => '2', 'flat_amount' => null])
        ->and($progress['next_tier']['threshold_amount'])->toBe('50000.00')
        ->and($progress['accrued_rebate'])->toBe('400.00')
        ->and($progress['distance_to_next'])->toBe('30000.00')
        ->and($progress['progress_percent'])->toBe('25.00')
        ->and($progress['projected_rebate_at_next'])->toBe('2000.00')
        ->and($progress['projected_gain'])->toBe('1600.00');
});

it('reports the distance to the first tier when nothing is reached yet', function () use ($scale): void {
    $progress = (new RebateTierCalculator)->progress($scale, '5000.00', 'retroactive');

    expect($progress['current_tier'])->toBeNull()
        ->and($progress['next_tier']['threshold_amount'])->toBe('10000.00')
        ->and($progress['accrued_rebate'])->toBe('0.00')
        ->and($progress['distance_to_next'])->toBe('5000.00')
        ->and($progress['progress_percent'])->toBe('50.00')
        ->and($progress['projected_rebate_at_next'])->toBe('200.00')
        ->and($progress['projected_gain'])->toBe('200.00');
});

it('reports a full bar and no distance on the top tier', function () use ($scale): void {
    $progress = (new RebateTierCalculator)->progress($scale, '60000.00', 'incremental');

    expect($progress['current_tier']['threshold_amount'])->toBe('50000.00')
        ->and($progress['next_tier'])->toBeNull()
        ->and($progress['distance_to_next'])->toBeNull()
        ->and($progress['progress_percent'])->toBe('100.00')
        ->and($progress['projected_rebate_at_next'])->toBeNull()
        ->and($progress['projected_gain'])->toBeNull()
        ->and($progress['accrued_rebate'])->toBe('1200.00');
});

it('sorts the tiers before computing, whatever the order they are given in', function () use ($scale): void {
    $calculator = new RebateTierCalculator;

    expect($calculator->rebateOn(array_reverse($scale), '60000.00', 'retroactive')['rebate'])->toBe('2400.00')
        ->and($calculator->progress(array_reverse($scale), '20000.00', 'retroactive')['next_tier']['threshold_amount'])->toBe('50000.00');
});
