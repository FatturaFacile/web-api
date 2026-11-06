<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use DateTimeImmutable;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayrollDates;

/**
 * Statutory rules of Swiss payroll that do not depend on company settings.
 */
final class SwissPayrollRules
{
    /** @var list<string> */
    private const array AnnualModelCantons = ['FR', 'GE', 'TI', 'VD', 'VS'];

    /**
     * The AHV reference age date (AHV 21 reform: 65 for men; for women born 1961-1963 a transitional age).
     */
    public static function referenceAgeDate(DateTimeImmutable $dateOfBirth, ?string $gender): DateTimeImmutable
    {
        if (strtoupper((string) $gender) !== 'F') {
            return PayrollDates::addYears($dateOfBirth, 65);
        }

        $birthYear = (int) $dateOfBirth->format('Y');
        $months = match (true) {
            $birthYear <= 1960 => -12,
            $birthYear === 1961 => -9,
            $birthYear === 1962 => -6,
            $birthYear === 1963 => -3,
            default => 0,
        };

        return PayrollDates::addMonths(PayrollDates::addYears($dateOfBirth, 65), $months);
    }

    /**
     * AHV/ALV contributions are due from 1 January following the 17th birthday.
     */
    public static function isContributionAge(?DateTimeImmutable $dateOfBirth, int $year): bool
    {
        return $dateOfBirth === null || $year >= ((int) $dateOfBirth->format('Y')) + 18;
    }

    /**
     * The BVG age credit in percent of the coordinated salary (0 below 25; BVG age = year - year of birth).
     */
    public static function bvgAgeCreditPercent(?DateTimeImmutable $dateOfBirth, int $year): string
    {
        $age = $dateOfBirth === null ? 40 : $year - (int) $dateOfBirth->format('Y');

        return match (true) {
            $age < 25 => '0',
            $age < 35 => '7',
            $age < 45 => '10',
            $age < 55 => '15',
            default => '18',
        };
    }

    /**
     * The annual coordinated (insured) BVG salary; 0 below the entry threshold.
     */
    public static function bvgCoordinatedSalary(string $annualSalary, SwissPayrollParameters $parameters = new SwissPayrollParameters): string
    {
        if (Dec::cmp($annualSalary, $parameters->bvgEntryThreshold) < 0) {
            return '0';
        }

        $capped = Dec::min($annualSalary, $parameters->bvgMaximumSalary);

        return Dec::max(Dec::sub($capped, $parameters->bvgCoordinationDeduction), $parameters->bvgMinimumCoordinatedSalary);
    }

    /**
     * Whether a canton applies the annual withholding tax model (FR, GE, TI, VD, VS).
     */
    public static function usesAnnualWithholdingTaxModel(?string $canton): bool
    {
        return $canton !== null && in_array(strtoupper($canton), self::AnnualModelCantons, true);
    }

    /**
     * The holiday compensation rate of hourly paid employees, weeks / (52 - weeks): 4 weeks = 8.33 %.
     */
    public static function holidayCompensationPercent(int $holidayWeeks): string
    {
        return $holidayWeeks <= 0
            ? '0'
            : Dec::round(Dec::div(Dec::mul((string) $holidayWeeks, '100'), (string) (52 - $holidayWeeks)));
    }

    /**
     * Rounds a contribution to 5 centimes.
     */
    public static function roundTo5Centimes(string $amount): string
    {
        return Dec::round(Dec::div(Dec::round(Dec::mul($amount, '20'), 0), '20'));
    }

    /**
     * The paid days of a month with the 30-day convention for an employment period (0 when not employed in the month).
     */
    public static function paidDays(int $year, int $month, DateTimeImmutable $hireDate, ?DateTimeImmutable $terminationDate): int
    {
        $first = PayrollDates::monthStart($year, $month);
        $last = PayrollDates::monthEnd($year, $month);
        $start = $hireDate > $first ? $hireDate : $first;
        $end = $terminationDate !== null && $terminationDate < $last ? $terminationDate : $last;

        if ($end < $start) {
            return 0;
        }

        $startDay = min((int) $start->format('j'), 30);
        $endDay = $end == $last ? 30 : min((int) $end->format('j'), 30);

        return max($endDay - $startDay + 1, 0);
    }

    /**
     * The absence days of a month (30-day convention, weighted by the percentage).
     */
    public static function absenceDays(int $year, int $month, DateTimeImmutable $startDate, DateTimeImmutable $endDate, string $percent): string
    {
        return Dec::div(Dec::mul((string) self::paidDays($year, $month, $startDate, $endDate), $percent), '100');
    }
}
