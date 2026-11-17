<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\RecurringContracts\PeriodicBillingBatchProcessor;
use App\Services\RecurringContracts\ProrationCalculatorService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PeriodicBillingUnitTest extends TestCase
{
    private PeriodicBillingBatchProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new PeriodicBillingBatchProcessor(new ProrationCalculatorService);
    }

    public function test_proration_advance_period_monthly(): void
    {
        $calculator = new ProrationCalculatorService;
        $start = CarbonImmutable::parse('2026-03-01');
        $period = $calculator->advancePeriod($start, 'monthly');

        $this->assertEquals('2026-03-01', $period['period_start']->toDateString());
        $this->assertEquals('2026-03-31', $period['period_end']->toDateString());
        $this->assertEquals('2026-04-01', $period['next_start']->toDateString());
    }

    public function test_proration_advance_period_quarterly(): void
    {
        $calculator = new ProrationCalculatorService;
        $start = CarbonImmutable::parse('2026-01-01');
        $period = $calculator->advancePeriod($start, 'quarterly');

        $this->assertEquals('2026-01-01', $period['period_start']->toDateString());
        $this->assertEquals('2026-03-31', $period['period_end']->toDateString());
        $this->assertEquals('2026-04-01', $period['next_start']->toDateString());
    }

    public function test_proration_advance_period_custom_interval(): void
    {
        $calculator = new ProrationCalculatorService;
        $start = CarbonImmutable::parse('2026-05-01');
        $period = $calculator->advancePeriod($start, 'custom_days', 14);

        $this->assertEquals('2026-05-01', $period['period_start']->toDateString());
        $this->assertEquals('2026-05-14', $period['period_end']->toDateString());
        $this->assertEquals('2026-05-15', $period['next_start']->toDateString());
    }
}
