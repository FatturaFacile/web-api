<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\AccrualCalculator;
use Modules\GeneralLedger\Services\Tax\Decimal;

test('counts inclusive calendar days accurately including leap years and boundaries', function (): void {
    $calc = new AccrualCalculator;

    expect($calc->inclusiveDays(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))->toBe(365)
        ->and($calc->inclusiveDays(CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-12-31')))->toBe(366)
        ->and($calc->inclusiveDays(CarbonImmutable::parse('2026-06-15'), CarbonImmutable::parse('2026-06-15')))->toBe(1)
        ->and($calc->inclusiveDays(CarbonImmutable::parse('2026-06-16'), CarbonImmutable::parse('2026-06-15')))->toBe(0);
});

test('computes penny-accurate 12-month prepaid expense deferral across fiscal years', function (): void {
    $calc = new AccrualCalculator;

    $result = $calc->calculate(
        totalAmount: '1200.00',
        start: CarbonImmutable::parse('2026-10-01'),
        end: CarbonImmutable::parse('2027-09-30'),
        fyStart: CarbonImmutable::parse('2026-01-01'),
        fyEnd: CarbonImmutable::parse('2026-12-31'),
        documentDate: CarbonImmutable::parse('2026-10-01'),
        direction: 'cost',
    );

    expect($result['accrual_type'])->toBe('prepaid_expense')
        ->and($result['total_days'])->toBe(365)
        ->and($result['period_days'])->toBe(92)
        ->and($result['transitory_days'])->toBe(273)
        ->and($result['recognized_amount'])->toBe('302.47')
        ->and($result['transitory_amount'])->toBe('897.53')
        ->and(Decimal::round2(Decimal::add($result['recognized_amount'], $result['transitory_amount'])))->toBe('1200.00')
        ->and($result['cutoff_date'])->toBe('2026-12-31')
        ->and($result['reversal_date'])->toBe('2027-01-01');
});

test('apportions multi-year licenses straddling leap year 2028 without penny loss', function (): void {
    $calc = new AccrualCalculator;

    // 36-month license: 2026-07-01 to 2029-06-30 (1096 days including 2028-02-29)
    $result = $calc->calculate(
        totalAmount: '1000.00',
        start: CarbonImmutable::parse('2026-07-01'),
        end: CarbonImmutable::parse('2029-06-30'),
        fyStart: CarbonImmutable::parse('2026-01-01'),
        fyEnd: CarbonImmutable::parse('2026-12-31'),
        documentDate: CarbonImmutable::parse('2026-07-01'),
        direction: 'cost',
    );

    $sum = '0.00';
    foreach ($result['yearly_schedule'] as $slice) {
        $sum = Decimal::round2(Decimal::add($sum, $slice['amount']));
    }

    expect($result['total_days'])->toBe(1096)
        ->and($result['yearly_schedule'])->toHaveCount(4)
        ->and($sum)->toBe('1000.00')
        ->and(Decimal::round2(Decimal::add($result['recognized_amount'], $result['transitory_amount'])))->toBe('1000.00');
});

test('largest-remainder apportionment handles odd cents cleanly', function (): void {
    $calc = new AccrualCalculator;

    // 100.00 across 3 equal single-day periods spanning 3 years (100 / 3 = 33.333...) -> 33.34 + 33.33 + 33.33
    $result = $calc->calculate(
        totalAmount: '100.00',
        start: CarbonImmutable::parse('2026-12-31'),
        end: CarbonImmutable::parse('2028-01-01'),
        fyStart: CarbonImmutable::parse('2026-01-01'),
        fyEnd: CarbonImmutable::parse('2026-12-31'),
        documentDate: CarbonImmutable::parse('2026-12-15'),
        direction: 'revenue',
    );

    $sum = '0.00';
    foreach ($result['yearly_schedule'] as $slice) {
        $sum = Decimal::round2(Decimal::add($sum, $slice['amount']));
    }

    expect($result['accrual_type'])->toBe('deferred_revenue')
        ->and($sum)->toBe('100.00')
        ->and(Decimal::round2(Decimal::add($result['recognized_amount'], $result['transitory_amount'])))->toBe('100.00');

    // Minimum 0.01 across 2 years
    $penny = $calc->calculate(
        totalAmount: '0.01',
        start: CarbonImmutable::parse('2026-12-31'),
        end: CarbonImmutable::parse('2027-01-01'),
        fyStart: CarbonImmutable::parse('2026-01-01'),
        fyEnd: CarbonImmutable::parse('2026-12-31'),
        documentDate: CarbonImmutable::parse('2026-12-31'),
        direction: 'cost',
    );

    expect(Decimal::round2(Decimal::add($penny['recognized_amount'], $penny['transitory_amount'])))->toBe('0.01');
});

test('classifies all four accrual and deferral types and zero-adjustment in-period items', function (): void {
    $calc = new AccrualCalculator;
    $fyStart = CarbonImmutable::parse('2026-01-01');
    $fyEnd = CarbonImmutable::parse('2026-12-31');

    // 1. Risconto Attivo (prepaid_expense)
    $riscontoAttivo = $calc->calculate('500.00', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-02-28'), $fyStart, $fyEnd, CarbonImmutable::parse('2026-11-01'), 'cost');
    // 2. Risconto Passivo (deferred_revenue)
    $riscontoPassivo = $calc->calculate('500.00', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-02-28'), $fyStart, $fyEnd, CarbonImmutable::parse('2026-11-01'), 'revenue');
    // 3. Rateo Attivo (accrued_revenue)
    $rateoAttivo = $calc->calculate('365.00', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2027-06-30'), $fyStart, $fyEnd, CarbonImmutable::parse('2027-07-01'), 'revenue');
    // 4. Rateo Passivo (accrued_expense)
    $rateoPassivo = $calc->calculate('365.00', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2027-06-30'), $fyStart, $fyEnd, CarbonImmutable::parse('2027-07-01'), 'cost');
    // 5. Wholly within fiscal year -> null classification, zero transitory
    $inYear = $calc->calculate('600.00', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-08-31'), $fyStart, $fyEnd, CarbonImmutable::parse('2026-03-01'), 'cost');

    expect($riscontoAttivo['accrual_type'])->toBe('prepaid_expense')
        ->and($riscontoPassivo['accrual_type'])->toBe('deferred_revenue')
        ->and($rateoAttivo['accrual_type'])->toBe('accrued_revenue')
        ->and($rateoAttivo['period_days'])->toBe(184)
        ->and($rateoAttivo['transitory_amount'])->toBe('184.00')
        ->and($rateoPassivo['accrual_type'])->toBe('accrued_expense')
        ->and($rateoPassivo['transitory_amount'])->toBe('184.00')
        ->and($inYear['accrual_type'])->toBeNull()
        ->and($inYear['transitory_amount'])->toBe('0.00')
        ->and($inYear['recognized_amount'])->toBe('600.00');
});
