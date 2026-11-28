<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\CrisisMonitoring\Domain\Dscr\DscrCalculator;
use Modules\CrisisMonitoring\Domain\Dscr\MonthlyBucket;
use Modules\CrisisMonitoring\Domain\Swiss\SwissCo725Evaluator;

/**
 * The monthly projection rows of twelve identical months.
 *
 * @return list<array<string, mixed>>
 */
function crisisSwissMonths(string $inflows, string $outflows, string $opening, string $credit): array
{
    $buckets = [];

    for ($index = 0; $index < 12; $index++) {
        $buckets[] = new MonthlyBucket(CarbonImmutable::parse('2030-01-10')->addMonthsNoOverflow($index), $inflows, $outflows);
    }

    return (new DscrCalculator)->project($buckets, $opening, $credit)['months'];
}

it('warns in red when the cash with the credit lines turns negative within six months (Art. 725 CO)', function (): void {
    $months = crisisSwissMonths('1000', '1500', '2000', '0');
    $result = (new SwissCo725Evaluator)->liquidity($months, '2000', '0');

    // 2000 - 500 per month: 1500, 1000, 500, 0, -500.
    expect($result['status'])->toBe('red')
        ->and($result['first_negative_month'])->toBe('2030-05-01')
        ->and($result['runway_months'])->toBe(4)
        ->and($result['min_cumulative_cash'])->toBe('-4000.00')
        ->and($result['min_month'])->toBe('2030-12-01')
        ->and($result['coverage_6m'])->toBe('0.89')
        ->and($result['plan'])->toHaveCount(12)
        ->and($result['plan'][0]['net'])->toBe('-500.00');
});

it('turns yellow when the shortfall comes after six months and the credit lines delay it', function (): void {
    $months = crisisSwissMonths('1000', '1500', '2000', '1000');
    $result = (new SwissCo725Evaluator)->liquidity($months, '2000', '1000');

    // 3000 available: 2500, 2000, 1500, 1000, 500, 0, -500 -> negative in the seventh month.
    expect($result['status'])->toBe('yellow')
        ->and($result['runway_months'])->toBe(6)
        ->and($result['first_negative_month'])->toBe('2030-07-01')
        ->and($result['committed_credit_lines'])->toBe('1000.00');
});

it('stays green with a positive cash runway over twelve months', function (): void {
    $months = crisisSwissMonths('2000', '1500', '2000', '0');
    $result = (new SwissCo725Evaluator)->liquidity($months, '2000', '0');

    expect($result['status'])->toBe('green')
        ->and($result['first_negative_month'])->toBeNull()
        ->and($result['runway_months'])->toBeNull()
        ->and($result['min_cumulative_cash'])->toBe('2500.00');
});

it('detects the loss of half of the share capital and reserves (Art. 725a CO)', function (): void {
    $evaluator = new SwissCo725Evaluator;
    $loss = $evaluator->capitalLoss('50000', '100000', '20000');
    $watch = $evaluator->capitalLoss('80000', '100000', '20000');
    $healthy = $evaluator->capitalLoss('100000', '100000', '20000');

    expect($loss['capital_loss'])->toBeTrue()->and($loss['status'])->toBe('red')
        ->and($loss['protected_capital'])->toBe('120000.00')
        ->and($loss['required_net_assets'])->toBe('60000.00')
        ->and($loss['coverage_ratio'])->toBe('0.42')
        ->and($loss['meter_percent'])->toBe(42)
        ->and($watch['capital_loss'])->toBeFalse()->and($watch['status'])->toBe('yellow')
        ->and($healthy['status'])->toBe('green')->and($healthy['meter_percent'])->toBe(83);
});

it('does not test the capital loss when the share capital is not configured', function (): void {
    $result = (new SwissCo725Evaluator)->capitalLoss('-5000', null, null);

    expect($result['configured'])->toBeFalse()->and($result['capital_loss'])->toBeFalse()->and($result['status'])->toBe('green')->and($result['coverage_ratio'])->toBeNull();
});

it('detects the over-indebtedness at going-concern and liquidation values (Art. 725b CO)', function (): void {
    $evaluator = new SwissCo725Evaluator;
    $over = $evaluator->overIndebtedness('800', '500', '1000', true, '0');

    expect($over['status'])->toBe('red')
        ->and($over['basis'])->toBe('going_concern')
        ->and($over['shortfall'])->toBe('200.00')
        ->and($over['remaining_shortfall'])->toBe('200.00')
        ->and($over['overindebted'])->toBeTrue()
        ->and($over['court_notification_required'])->toBeTrue()
        ->and($over['going_concern']['net'])->toBe('-200.00')
        ->and($over['liquidation']['net'])->toBe('-500.00');
});

it('cures the over-indebtedness with a subordination and turns it yellow', function (): void {
    $cured = (new SwissCo725Evaluator)->overIndebtedness('800', '500', '1000', true, '200');

    expect($cured['status'])->toBe('yellow')
        ->and($cured['remaining_shortfall'])->toBe('0.00')
        ->and($cured['overindebted'])->toBeFalse()
        ->and($cured['court_notification_required'])->toBeFalse();
});

it('is not over-indebted when the liquidation values cover the liabilities, or when the continuation is probable and covered', function (): void {
    $evaluator = new SwissCo725Evaluator;
    $liquidationCovers = $evaluator->overIndebtedness('800', '1200', '1000', true, '0');
    $goingConcernCovers = $evaluator->overIndebtedness('1100', '500', '1000', true, '0');
    $notProbable = $evaluator->overIndebtedness('1100', '500', '1000', false, '0');

    expect($liquidationCovers['status'])->toBe('green')
        ->and($goingConcernCovers['status'])->toBe('green')
        ->and($goingConcernCovers['shortfall'])->toBe('0.00')
        ->and($notProbable['status'])->toBe('red')
        ->and($notProbable['basis'])->toBe('liquidation')
        ->and($notProbable['shortfall'])->toBe('500.00');
});
