<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\RecurringContracts\ProrationCalculatorService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ProrationCalculatorTest extends TestCase
{
    private ProrationCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ProrationCalculatorService;
    }

    public function test_proration_mode_none_returns_full_amount(): void
    {
        $amount = $this->calculator->calculateProratedAmount(
            '100.0000',
            'none',
            CarbonImmutable::parse('2026-03-15'),
            CarbonImmutable::parse('2026-03-31'),
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31')
        );

        $this->assertEquals('100.0000', $amount);
    }

    public function test_proration_mode_daily_exact_for_partial_period(): void
    {
        // 17 days active (March 15 to March 31 inclusive) out of 31 days in March
        // 100 * (17 / 31) = 54.8387
        $amount = $this->calculator->calculateProratedAmount(
            '100.0000',
            'daily_exact',
            CarbonImmutable::parse('2026-03-15'),
            CarbonImmutable::parse('2026-03-31'),
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31')
        );

        $this->assertEquals('54.8387', $amount);
    }

    public function test_proration_mode_daily_exact_in_leap_year_february(): void
    {
        // 2028 is a leap year: Feb 1 to Feb 29 (29 days)
        // Active Feb 15 to Feb 29 (15 days inclusive)
        // 100 * (15 / 29) = 51.7241
        $amount = $this->calculator->calculateProratedAmount(
            '100.0000',
            'daily_exact',
            CarbonImmutable::parse('2028-02-15'),
            CarbonImmutable::parse('2028-02-29'),
            CarbonImmutable::parse('2028-02-01'),
            CarbonImmutable::parse('2028-02-29')
        );

        $this->assertEquals('51.7241', $amount);
    }

    public function test_proration_mode_thirty_day_month_convention(): void
    {
        // March 15 to March 31 with 30/360:
        // d1 = 15, d2 = 30 (since last day of month)
        // days = 30 - 15 + 1 = 16 days
        // 100 * (16 / 30) = 53.3333
        $amount = $this->calculator->calculateProratedAmount(
            '100.0000',
            'thirty_day_month',
            CarbonImmutable::parse('2026-03-15'),
            CarbonImmutable::parse('2026-03-31'),
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31')
        );

        $this->assertEquals('53.3333', $amount);
    }

    public function test_advance_period_preserves_jan_31_anchor_to_february(): void
    {
        // Start date Jan 31 -> February end is Feb 28 (non-leap year)
        $result = $this->calculator->advancePeriod(
            CarbonImmutable::parse('2026-01-31'),
            'monthly'
        );

        $this->assertEquals('2026-01-31', $result['period_start']->toDateString());
        $this->assertEquals('2026-02-27', $result['period_end']->toDateString());
        $this->assertEquals('2026-02-28', $result['next_start']->toDateString());
    }
}
