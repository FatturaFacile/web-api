<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\CrisisMonitoring\Domain\Dscr\DscrBands;
use Modules\CrisisMonitoring\Domain\Dscr\DscrCalculator;
use Modules\CrisisMonitoring\Domain\Dscr\MonthlyBucket;

/**
 * Twelve identical months, the first one optionally carrying overdue suppliers.
 *
 * @return list<MonthlyBucket>
 */
function crisisBuckets(string $inflows, string $operating, string $debt, string $tax = '0', string $overdue = '0'): array
{
    $buckets = [];

    for ($index = 0; $index < 12; $index++) {
        $buckets[] = new MonthlyBucket(CarbonImmutable::parse('2030-01-15')->addMonthsNoOverflow($index), $inflows, $operating, $debt, $tax, $index === 0 ? $overdue : '0', '0');
    }

    return $buckets;
}

it('computes the treasury DSCR (approach 1) over six and twelve months with the opening cash', function (): void {
    $result = (new DscrCalculator)->project(crisisBuckets('1000', '400', '300', '100', '200'), '500', '0');

    // 6 months: free cash flow 6 x 600 = 3600 + 500 opening; debt service 6 x 400 + 200 overdue suppliers = 2600.
    expect($result['dscr']['treasury']['m6']['value'])->toBe('1.58')
        ->and($result['dscr']['treasury']['m6']['status'])->toBe('green')
        ->and($result['dscr']['treasury']['m6']['numerator'])->toBe('4100.00')
        ->and($result['dscr']['treasury']['m6']['denominator'])->toBe('2600.00')
        ->and($result['dscr']['treasury']['m12']['denominator'])->toBe('5000.00');
});

it('computes the operating DSCR (approach 2) after taxes with the credit lines', function (): void {
    $result = (new DscrCalculator)->project(crisisBuckets('1000', '400', '300', '100'), '0', '600');

    // 6 months: (1000 - 400 - 100) x 6 = 3000 + 600 credit lines over 6 x 300 financial debt service.
    expect($result['dscr']['operating']['m6']['value'])->toBe('2.00')
        ->and($result['dscr']['operating']['m6']['numerator'])->toBe('3600.00')
        ->and($result['dscr']['operating']['m6']['denominator'])->toBe('1800.00');
});

it('classifies red below 1.0, yellow from 1.0 to 1.2 and green above 1.2', function (): void {
    $calculator = new DscrCalculator;
    $red = $calculator->treasury(array_slice(crisisBuckets('300', '100', '400'), 0, 6), '0');
    $oneExactly = $calculator->treasury(array_slice(crisisBuckets('1000', '500', '500'), 0, 6), '0');
    $twelveExactly = $calculator->treasury(array_slice(crisisBuckets('1000', '500', '500'), 0, 6), '600');
    $green = $calculator->treasury(array_slice(crisisBuckets('1000', '500', '500'), 0, 6), '700');

    expect($red['status'])->toBe('red')->and($red['value'])->toBe('0.50')
        ->and($oneExactly['status'])->toBe('yellow')->and($oneExactly['value'])->toBe('1.00')
        ->and($twelveExactly['status'])->toBe('yellow')->and($twelveExactly['value'])->toBe('1.20')
        ->and($green['status'])->toBe('green')->and($green['value'])->toBe('1.23');
});

it('uses the thresholds of the company settings', function (): void {
    $strict = new DscrCalculator(new DscrBands('1.50', '2.00'));
    $result = $strict->treasury(array_slice(crisisBuckets('1000', '500', '500'), 0, 6), '700');

    expect($result['status'])->toBe('red');
});

it('is not applicable without any debt service and stays green', function (): void {
    $result = (new DscrCalculator)->project(crisisBuckets('1000', '400', '0'), '0', '0');

    expect($result['dscr']['treasury']['m6']['applicable'])->toBeFalse()
        ->and($result['dscr']['treasury']['m6']['value'])->toBeNull()
        ->and($result['dscr']['treasury']['m6']['status'])->toBe('green')
        ->and($result['dscr']['operating']['m12']['applicable'])->toBeFalse();
});

it('builds twelve monthly rows with the cumulative cash and the rolling DSCR', function (): void {
    $result = (new DscrCalculator)->project(crisisBuckets('1000', '400', '300', '100', '200'), '500', '250');
    $months = $result['months'];

    expect($months)->toHaveCount(12)
        ->and($months[0]['month'])->toBe('2030-01-01')
        ->and($months[0]['free_cash_flow'])->toBe('600.00')
        ->and($months[0]['debt_service'])->toBe('600.00')
        ->and($months[0]['closing_cash'])->toBe('500.00')
        ->and($months[0]['cumulative_cash_with_credit'])->toBe('750.00')
        ->and($months[0]['dscr_treasury'])->toBe('1.83')
        ->and($months[1]['closing_cash'])->toBe('700.00')
        ->and($months[11]['month'])->toBe('2030-12-01');
});
