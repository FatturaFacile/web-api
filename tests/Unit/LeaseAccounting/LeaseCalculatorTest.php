<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\LeaseAccounting\Support\LeaseCalculator;
use Modules\LeaseAccounting\Support\LeaseMoney;
use Modules\LeaseAccounting\Support\LeaseTerms;

test('the present value of a flat annuity in arrears matches the closed form', function (): void {
    $terms = new LeaseTerms('1000.00', 1, 'arrears');
    $plan = LeaseCalculator::paymentPlan($terms, 1, 12);

    expect(LeaseCalculator::presentValue($plan, '6'))->toBe('11618.93')
        ->and(LeaseCalculator::totalPayments($plan))->toBe('12000.00');
});

test('a zero rate discounts nothing and charges no interest', function (): void {
    $terms = new LeaseTerms('250.00', 3, 'advance');
    $plan = LeaseCalculator::paymentPlan($terms, 1, 12);
    $liability = LeaseCalculator::presentValue($plan, '0');
    $lines = LeaseCalculator::buildLines($plan, CarbonImmutable::parse('2027-01-15'), '0', $liability, $liability, 12);

    expect($liability)->toBe('1000.00')
        ->and(array_sum(array_map(static fn (array $line): float => (float) $line['interest'], $lines)))->toEqual(0.0)
        ->and($lines[0]['payment'])->toBe('250.00')
        ->and($lines[1]['payment'])->toBe('0.00')
        ->and($lines[9]['closing_liability'])->toBe('0.00')
        ->and($lines[0]['period_start'])->toBe('2027-01-15')
        ->and($lines[0]['period_end'])->toBe('2027-02-14');
});

test('the schedule always closes at zero and the rounding residual stays within a few cents', function (string $timing, int $frequency, string $rate): void {
    $terms = new LeaseTerms('1234.57', $frequency, $timing, '2.500000');
    $plan = LeaseCalculator::paymentPlan($terms, 1, 60);
    $liability = LeaseCalculator::presentValue($plan, $rate);
    $lines = LeaseCalculator::buildLines($plan, CarbonImmutable::parse('2027-01-31'), $rate, $liability, '50000.00', 60);

    $exactInterest = '0';
    $balance = $liability;
    $monthly = LeaseCalculator::monthlyRate($rate);
    foreach ($lines as $line) {
        $balance = LeaseMoney::sub($balance, $line['principal']);
        $exactInterest = LeaseMoney::add($exactInterest, $line['interest']);
    }

    $depreciation = array_reduce($lines, static fn (string $sum, array $line): string => LeaseMoney::add($sum, $line['depreciation']), '0');

    expect(end($lines)['closing_liability'])->toBe('0.00')
        ->and(LeaseMoney::round($balance))->toBe('0.00')
        ->and(LeaseMoney::round($depreciation))->toBe('50000.00')
        ->and(end($lines)['rou_closing'])->toBe('0.00')
        ->and($monthly)->not->toBe('0');

    foreach ($lines as $index => $line) {
        expect(LeaseMoney::compare(LeaseMoney::sub($line['opening_liability'], $line['principal']), $line['closing_liability']))->toBe(0, "period {$line['period_number']}");

        if ($index > 0) {
            expect($line['opening_liability'])->toBe($lines[$index - 1]['closing_liability']);
        }
    }
})->with([
    'arrears monthly' => ['arrears', 1, '4.75'],
    'advance quarterly' => ['advance', 3, '6.10'],
    'arrears annual' => ['arrears', 12, '3.25'],
]);

test('month ends do not drift: periods follow the commencement day', function (): void {
    $start = CarbonImmutable::parse('2027-01-31');

    expect(LeaseCalculator::periodStart($start, 2)->toDateString())->toBe('2027-02-28')
        ->and(LeaseCalculator::periodStart($start, 3)->toDateString())->toBe('2027-03-31')
        ->and(LeaseCalculator::periodEnd($start, 1)->toDateString())->toBe('2027-02-27');
});

test('a purchase option extends the depreciation to the useful life', function (): void {
    expect(LeaseCalculator::depreciationMonths(1, 24, 120, true))->toBe(120)
        ->and(LeaseCalculator::depreciationMonths(7, 24, 120, true))->toBe(114)
        ->and(LeaseCalculator::depreciationMonths(1, 24, 120, false))->toBe(24)
        ->and(LeaseCalculator::depreciationMonths(1, 24, null, true))->toBe(24);
});

test('lease money refuses floats and malformed numbers', function (): void {
    expect(fn () => LeaseMoney::normalize('12,5'))->toThrow(InvalidArgumentException::class)
        ->and(LeaseMoney::round('2.005'))->toBe('2.01')
        ->and(LeaseMoney::round('-2.005'))->toBe('-2.01');
});
