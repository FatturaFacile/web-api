<?php

declare(strict_types=1);

namespace App\Services\RecurringContracts;

use Carbon\CarbonImmutable;

class ProrationCalculatorService
{
    /**
     * Calculate prorated line amount for a period.
     *
     * @param  string  $fullPeriodicPrice  Line subtotal for the entire regular frequency (e.g. monthly)
     * @param  string  $prorationMode  'none' | 'daily_exact' | 'thirty_day_month'
     * @param  CarbonImmutable  $periodStart  Start of actual usage/billing
     * @param  CarbonImmutable  $periodEnd  End of actual usage/billing (inclusive)
     * @param  CarbonImmutable  $cycleStart  Start of regular full billing cycle
     * @param  CarbonImmutable  $cycleEnd  End of regular full billing cycle (inclusive)
     * @return string Prorated amount formatted to 4 decimals
     */
    public function calculateProratedAmount(
        string $fullPeriodicPrice,
        string $prorationMode,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $cycleStart,
        CarbonImmutable $cycleEnd
    ): string {
        if ($prorationMode === 'none') {
            return bcmul($fullPeriodicPrice, '1.0000', 4);
        }

        if ($periodStart->greaterThan($periodEnd)) {
            return '0.0000';
        }

        // If the period covers the entire cycle, return full price
        if ($periodStart->equalTo($cycleStart) && $periodEnd->equalTo($cycleEnd)) {
            return bcmul($fullPeriodicPrice, '1.0000', 4);
        }

        if ($prorationMode === 'daily_exact') {
            // Actual days between periodStart and periodEnd (inclusive)
            $actualDays = $periodStart->diffInDays($periodEnd) + 1;
            // Total days in regular cycle (inclusive)
            $cycleDays = $cycleStart->diffInDays($cycleEnd) + 1;

            if ($cycleDays <= 0) {
                return '0.0000';
            }

            $ratio = bcdiv((string) $actualDays, (string) $cycleDays, 8);

            return bcmul($fullPeriodicPrice, $ratio, 4);
        }

        if ($prorationMode === 'thirty_day_month') {
            // 30/360 European bond basis convention:
            // Days = (Y2 - Y1)*360 + (M2 - M1)*30 + (D2 - D1)
            $d1 = min(30, $periodStart->day);
            $d2 = min(30, $periodEnd->day);
            if ($periodEnd->isLastOfMonth() && $periodEnd->day >= 28) {
                $d2 = 30;
            }
            if ($periodStart->isLastOfMonth() && $periodStart->day >= 28) {
                $d1 = min(30, $d1);
            }

            $days = ($periodEnd->year - $periodStart->year) * 360
                + ($periodEnd->month - $periodStart->month) * 30
                + ($d2 - $d1) + 1;

            // Standard month in 30/360 is 30 days
            $ratio = bcdiv((string) max(0, $days), '30', 8);

            return bcmul($fullPeriodicPrice, $ratio, 4);
        }

        return bcmul($fullPeriodicPrice, '1.0000', 4);
    }

    /**
     * Compute next cycle end date given frequency and anchor date, handling month-end date clamping.
     * e.g. Jan 31 + 1 month -> Feb 28 (or Feb 29 in leap year), but preserving anchor day 31 on subsequent cycles.
     */
    public function advancePeriod(
        CarbonImmutable $startDate,
        string $frequency,
        ?int $customIntervalDays = null,
        ?int $anchorDay = null
    ): array {
        $anchor = $anchorDay ?? $startDate->day;

        switch ($frequency) {
            case 'monthly':
                $nextMonth = $startDate->addMonthNoOverflow();
                $targetDaysInMonth = $nextMonth->daysInMonth;
                $clampedDay = min($anchor, $targetDaysInMonth);
                $periodEnd = $startDate->addMonthNoOverflow()->day($clampedDay)->subDay();
                $nextStart = $periodEnd->addDay();
                break;

            case 'quarterly':
                $nextQuarter = $startDate->addMonthsNoOverflow(3);
                $clampedDay = min($anchor, $nextQuarter->daysInMonth);
                $periodEnd = $nextQuarter->day($clampedDay)->subDay();
                $nextStart = $periodEnd->addDay();
                break;

            case 'semi_annual':
                $nextHalf = $startDate->addMonthsNoOverflow(6);
                $clampedDay = min($anchor, $nextHalf->daysInMonth);
                $periodEnd = $nextHalf->day($clampedDay)->subDay();
                $nextStart = $periodEnd->addDay();
                break;

            case 'annual':
                $nextYear = $startDate->addYearNoOverflow();
                $clampedDay = min($anchor, $nextYear->daysInMonth);
                $periodEnd = $nextYear->day($clampedDay)->subDay();
                $nextStart = $periodEnd->addDay();
                break;

            case 'custom_days':
                $days = max(1, $customIntervalDays ?? 30);
                $periodEnd = $startDate->addDays($days - 1);
                $nextStart = $periodEnd->addDay();
                break;

            default:
                $periodEnd = $startDate->addMonthNoOverflow()->subDay();
                $nextStart = $periodEnd->addDay();
                break;
        }

        return [
            'period_start' => $startDate,
            'period_end' => $periodEnd,
            'next_start' => $nextStart,
        ];
    }
}
