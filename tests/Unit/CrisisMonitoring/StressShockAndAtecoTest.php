<?php

declare(strict_types=1);

use Modules\CrisisMonitoring\Application\Services\StressTestSummary;
use Modules\CrisisMonitoring\Domain\Enums\TrafficLight;
use Modules\CrisisMonitoring\Domain\Stress\StressShock;
use Modules\CrisisMonitoring\Support\AtecoSection;

it('clamps every value of the shock into its range', function (): void {
    $shock = StressShock::fromArray(['revenue_drop_percent' => '150', 'dso_delay_days' => 999, 'interest_rate_bps' => 5000, 'one_off_outflow_amount' => '-5']);

    expect($shock->revenueDropPercent)->toBe('100.00')
        ->and($shock->dsoDelayDays)->toBe(180)
        ->and($shock->interestRateBps)->toBe(2000)
        ->and($shock->oneOffOutflowAmount)->toBe('0.00')
        ->and($shock->isNeutral())->toBeFalse();
});

it('recognises the neutral shock and ignores what is not a number', function (): void {
    expect(StressShock::fromArray([])->isNeutral())->toBeTrue()
        ->and(StressShock::fromArray(['revenue_drop_percent' => 'abc', 'one_off_outflow_amount' => 'x'])->isNeutral())->toBeTrue()
        ->and(StressShock::fromArray(['dso_delay_days' => 30])->toArray())->toBe(['revenue_drop_percent' => '0.00', 'dso_delay_days' => 30, 'interest_rate_bps' => 0, 'one_off_outflow_amount' => '0.00']);
});

it('derives the ATECO section from the division of the code', function (): void {
    expect(AtecoSection::fromCode('62.01'))->toBe('J')
        ->and(AtecoSection::fromCode('6201'))->toBe('J')
        ->and(AtecoSection::fromCode('01.11'))->toBe('A')
        ->and(AtecoSection::fromCode('10.71'))->toBe('C')
        ->and(AtecoSection::fromCode('35.11'))->toBe('D')
        ->and(AtecoSection::fromCode('41'))->toBe('F')
        ->and(AtecoSection::fromCode('47.19'))->toBe('G')
        ->and(AtecoSection::fromCode('68'))->toBe('L')
        ->and(AtecoSection::fromCode('96.09'))->toBe('S')
        ->and(AtecoSection::fromCode('97'))->toBeNull()
        ->and(AtecoSection::fromCode('00'))->toBeNull()
        ->and(AtecoSection::fromCode(''))->toBeNull()
        ->and(AtecoSection::fromCode(null))->toBeNull()
        ->and(AtecoSection::isValid('S'))->toBeTrue()
        ->and(AtecoSection::isValid('T'))->toBeFalse();
});

it('orders the traffic lights by severity and picks the worst', function (): void {
    expect(TrafficLight::worst([]))->toBe(TrafficLight::Green)
        ->and(TrafficLight::worst(['green', 'yellow']))->toBe(TrafficLight::Yellow)
        ->and(TrafficLight::worst([TrafficLight::Yellow, 'red', 'green']))->toBe(TrafficLight::Red);
});

it('computes the differences between a baseline and a stressed summary', function (): void {
    $dscr = static fn (string $t6, string $t12): array => [
        'treasury' => ['m6' => ['value' => $t6], 'm12' => ['value' => $t12]],
        'operating' => ['m6' => ['value' => null], 'm12' => ['value' => '1.00']],
    ];
    $baseline = ['dscr' => $dscr('1.50', '1.40'), 'liquidity' => ['min_cumulative_cash' => '1000.00'], 'capital' => ['coverage_ratio' => '0.80']];
    $stressed = ['dscr' => $dscr('1.10', '1.45'), 'liquidity' => ['min_cumulative_cash' => '-250.50'], 'capital' => ['coverage_ratio' => null]];

    $deltas = StressTestSummary::deltas($baseline, $stressed);

    expect($deltas['dscr_treasury_m6'])->toBe('-0.40')
        ->and($deltas['dscr_treasury_m12'])->toBe('0.05')
        ->and($deltas['dscr_operating_m6'])->toBeNull()
        ->and($deltas['dscr_operating_m12'])->toBe('0.00')
        ->and($deltas['min_cumulative_cash'])->toBe('-1250.50')
        ->and($deltas['coverage_ratio'])->toBeNull();
});
