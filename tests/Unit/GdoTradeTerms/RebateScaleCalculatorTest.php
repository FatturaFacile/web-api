<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;
use Modules\GdoTradeTerms\Services\RebateScaleCalculator;
use Modules\GdoTradeTerms\Services\ScaleSimulator;

/**
 * @return list<array{threshold: string, rate_percent: string, flat_amount: string|null}>
 */
function gdtTiers(): array
{
    return [
        ['threshold' => '100000', 'rate_percent' => '1', 'flat_amount' => null],
        ['threshold' => '200000', 'rate_percent' => '2', 'flat_amount' => null],
        ['threshold' => '500000', 'rate_percent' => '3', 'flat_amount' => '1000'],
    ];
}

it('earns nothing below the first threshold or without volume', function (): void {
    $calculator = new RebateScaleCalculator;

    foreach ([TierMode::Retroactive, TierMode::Progressive] as $mode) {
        expect($calculator->earn(gdtTiers(), '99999.99', $mode)['rebate'])->toBe('0.0000')
            ->and($calculator->earn(gdtTiers(), '0', $mode)['tier'])->toBeNull()
            ->and($calculator->earn(gdtTiers(), '-500', $mode)['rebate'])->toBe('0.0000');
    }
});

it('applies the rate of the highest tier reached to the whole volume when retroactive', function (): void {
    $calculator = new RebateScaleCalculator;

    expect($calculator->earn(gdtTiers(), '100000', TierMode::Retroactive))->toBe(['rebate' => '1000.0000', 'rate_percent' => '1.0000', 'tier' => 1])
        ->and($calculator->earn(gdtTiers(), '150000', TierMode::Retroactive)['rebate'])->toBe('1500.0000')
        ->and($calculator->earn(gdtTiers(), '250000', TierMode::Retroactive)['rebate'])->toBe('5000.0000')
        // 3% of 600,000 plus the flat amount of the third tier.
        ->and($calculator->earn(gdtTiers(), '600000', TierMode::Retroactive)['rebate'])->toBe('19000.0000');
});

it('earns every slice at the rate of its own tier when progressive', function (): void {
    $calculator = new RebateScaleCalculator;

    expect($calculator->earn(gdtTiers(), '150000', TierMode::Progressive)['rebate'])->toBe('500.0000')
        ->and($calculator->earn(gdtTiers(), '250000', TierMode::Progressive)['rebate'])->toBe('2000.0000')
        // 1,000 + 300,000 x 2% + 100,000 x 3% + the flat amount of the third tier.
        ->and($calculator->earn(gdtTiers(), '600000', TierMode::Progressive))->toBe(['rebate' => '11000.0000', 'rate_percent' => '3.0000', 'tier' => 3]);
});

it('sorts the tiers before reading them', function (): void {
    $calculator = new RebateScaleCalculator;

    expect($calculator->earn(array_reverse(gdtTiers()), '250000', TierMode::Progressive)['rebate'])->toBe('2000.0000');
});

it('counts the whole volume or only the growth over the baseline, never a negative one', function (): void {
    $calculator = new RebateScaleCalculator;

    expect($calculator->qualifying('150000', '100000', VolumeMode::Total))->toBe('150000.0000')
        ->and($calculator->qualifying('150000', '100000', VolumeMode::Incremental))->toBe('50000.0000')
        ->and($calculator->qualifying('80000', '100000', VolumeMode::Incremental))->toBe('0.0000')
        ->and($calculator->qualifying('-10', '0', VolumeMode::Total))->toBe('0.0000');
});

it('projects the run rate of the elapsed period to the end of the year', function (): void {
    $calculator = new RebateScaleCalculator;
    $start = Carbon::parse('2028-01-01');
    $end = Carbon::parse('2028-12-31');

    $projection = $calculator->projection(gdtTiers(), TierMode::Retroactive, VolumeMode::Total, '100000', '0', $start, $end, Carbon::parse('2028-03-31'));

    // 91 of 366 days elapsed: 100,000 x 366 / 91.
    expect($projection['elapsed_days'])->toBe(91)
        ->and($projection['total_days'])->toBe(366)
        ->and($projection['projected_volume'])->toBe('402197.8022')
        ->and($projection['projected_tier'])->toBe(2)
        ->and($projection['projected_rebate'])->toBe('8043.9560')
        ->and($projection['tier_to_date'])->toBe(1)
        ->and($projection['earned_to_date'])->toBe('1000.0000')
        ->and($projection['next_tier']['threshold'])->toBe('500000.0000')
        ->and($projection['gap_to_next'])->toBe('97802.1978')
        ->and($projection['required_daily_volume'])->toBe('1454.5455');
});

it('projects nothing before the period starts and the actual volume once it has ended', function (): void {
    $calculator = new RebateScaleCalculator;
    $start = Carbon::parse('2028-01-01');
    $end = Carbon::parse('2028-12-31');

    $before = $calculator->projection(gdtTiers(), TierMode::Retroactive, VolumeMode::Total, '0', '0', $start, $end, Carbon::parse('2027-12-15'));
    $after = $calculator->projection(gdtTiers(), TierMode::Retroactive, VolumeMode::Total, '250000', '0', $start, $end, Carbon::parse('2029-02-01'));

    expect($before['elapsed_days'])->toBe(0)->and($before['projected_volume'])->toBe('0.0000')->and($before['projected_rebate'])->toBe('0.0000')
        ->and($after['remaining_days'])->toBe(0)->and($after['projected_volume'])->toBe('250000.0000')->and($after['projected_rebate'])->toBe('5000.0000')
        ->and($after['required_daily_volume'])->toBeNull();
});

it('projects the growth over the baseline with exact figures', function (): void {
    $calculator = new RebateScaleCalculator;
    $tiers = [['threshold' => '10000', 'rate_percent' => '2', 'flat_amount' => null]];

    // 100 of 200 days elapsed (a 200-day window), 60,000 sold: projected 120,000, growth over 100,000 is 20,000.
    $projection = $calculator->projection($tiers, TierMode::Retroactive, VolumeMode::Incremental, '60000', '100000', Carbon::parse('2027-01-01'), Carbon::parse('2027-07-19'), Carbon::parse('2027-04-10'));

    expect($projection['total_days'])->toBe(200)
        ->and($projection['elapsed_days'])->toBe(100)
        ->and($projection['projected_volume'])->toBe('120000.0000')
        ->and($projection['projected_qualifying'])->toBe('20000.0000')
        ->and($projection['projected_rebate'])->toBe('400.0000')
        ->and($projection['qualifying_to_date'])->toBe('0.0000');
});

it('simulates both readings of the brackets and the cliff at every threshold', function (): void {
    $simulation = (new ScaleSimulator(new RebateScaleCalculator))->simulate('retroactive', 'total', '0', gdtTiers(), ['150000', '250000']);

    expect($simulation['rows'][0]['retroactive']['rebate'])->toBe('1500.0000')
        ->and($simulation['rows'][0]['progressive']['rebate'])->toBe('500.0000')
        ->and($simulation['rows'][1]['selected']['rebate'])->toBe('5000.0000')
        ->and($simulation['rows'][1]['effective_percent'])->toBe('2.0000')
        // Retroactive: reaching 200,000 pays 2% of everything, 1% of 199,999.9999 just before.
        ->and($simulation['cliffs'][1]['jump'])->toBe('2000.0000')
        ->and($simulation['cliffs'])->toHaveCount(3);
});
