<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The billing periods of a subscription. Anniversary periods are anchored on the first billing date, without month-end
 * drift (Jan 31 -> Feb 28 -> Mar 31). Calendar periods start on the first day of the month, quarter, half year or year;
 * the first one runs from the billing date to the end of its calendar period and is charged pro rata by days.
 */
final class BillingCalendar
{
    public const string ANNIVERSARY = 'anniversary';

    public const string CALENDAR = 'calendar';

    /** @var array<string, int> */
    private const array MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'semiannual' => 6,
        'annual' => 12,
        'yearly' => 12,
        'multi_year' => 24,
    ];

    /**
     * The intervals a plan can be billed in.
     *
     * @return list<string>
     */
    public static function intervals(): array
    {
        return ['monthly', 'quarterly', 'semiannual', 'annual'];
    }

    /**
     * Months in one billing period of the interval, times the interval count.
     */
    public static function monthsPerPeriod(string $interval, int $count = 1): int
    {
        $months = self::MONTHS[$interval] ?? throw new InvalidArgumentException("Unknown billing interval [{$interval}].");

        return $months * max(1, $count);
    }

    /**
     * Start and end (inclusive) of the billing period that begins on `$periodStart`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(CarbonInterface $anchor, CarbonInterface $periodStart, string $interval, int $count, string $alignment): array
    {
        $start = CarbonImmutable::instance($periodStart)->startOfDay();
        $next = self::nextPeriodStart($anchor, $start, $interval, $count, $alignment);

        return [$start, $next->subDay()];
    }

    /**
     * First day of the period after the one starting on `$periodStart`.
     */
    public static function nextPeriodStart(CarbonInterface $anchor, CarbonInterface $periodStart, string $interval, int $count, string $alignment): CarbonImmutable
    {
        $months = self::monthsPerPeriod($interval, $count);
        $current = CarbonImmutable::instance($periodStart)->startOfDay();

        if ($alignment === self::CALENDAR) {
            return self::calendarPeriodStart($current, $months)->addMonthsNoOverflow($months);
        }

        $anchorDate = CarbonImmutable::instance($anchor)->startOfDay();

        for ($step = 1; $step <= 1200; $step++) {
            $candidate = $anchorDate->addMonthsNoOverflow($months * $step);

            if ($candidate->gt($current)) {
                return $candidate;
            }
        }

        return $current->addMonthsNoOverflow($months);
    }

    /**
     * Length in days of a full period of the interval containing `$date`, used for pro rata charges.
     */
    public static function fullPeriodDays(CarbonInterface $anchor, CarbonInterface $date, string $interval, int $count, string $alignment): int
    {
        $months = self::monthsPerPeriod($interval, $count);
        $day = CarbonImmutable::instance($date)->startOfDay();

        if ($alignment === self::CALENDAR) {
            $start = self::calendarPeriodStart($day, $months);

            return (int) $start->diffInDays($start->addMonthsNoOverflow($months));
        }

        $next = self::nextPeriodStart($anchor, $day, $interval, $count, self::ANNIVERSARY);
        $start = $next->subMonthsNoOverflow($months);

        return (int) $start->diffInDays($next);
    }

    /**
     * Inclusive number of days between two dates.
     */
    public static function days(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->startOfDay();

        return $end->lt($start) ? 0 : (int) $start->diffInDays($end) + 1;
    }

    /**
     * The share of a full period covered by a period, as a decimal string between 0 and 1.
     */
    public static function fraction(CarbonInterface $anchor, CarbonInterface $periodStart, CarbonInterface $periodEnd, string $interval, int $count, string $alignment): string
    {
        $full = self::fullPeriodDays($anchor, $periodStart, $interval, $count, $alignment);
        $covered = self::days($periodStart, $periodEnd);

        return Money::min('1', Money::div((string) $covered, (string) max(1, $full)));
    }

    /**
     * Months a period of the interval represents, for the monthly recurring revenue of its price.
     */
    public static function mrrDivisor(string $interval, int $count = 1): int
    {
        return self::monthsPerPeriod($interval, $count);
    }

    /**
     * First day of the calendar period of `$months` months containing the date (months 1, 4, 7, 10 for quarters).
     */
    private static function calendarPeriodStart(CarbonImmutable $date, int $months): CarbonImmutable
    {
        if ($months >= 12) {
            return $date->startOfYear();
        }

        $index = intdiv($date->month - 1, $months) * $months + 1;

        return $date->setDate($date->year, $index, 1)->startOfDay();
    }
}
