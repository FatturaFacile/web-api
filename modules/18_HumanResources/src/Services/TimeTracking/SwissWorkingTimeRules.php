<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Working and rest time rules of the Swiss Labour Act (LL / ArG) and its ordinance 1 (OLL 1 / ArGV 1).
 */
final class SwissWorkingTimeRules
{
    /** The working time is always evaluated in local time, whatever the time zone of the records. */
    public const TIMEZONE = 'Europe/Zurich';

    /** Maximum span of day and evening work including breaks, in minutes (art. 10 cpv. 3 LL: 14 hours). */
    public const MAXIMUM_DAILY_SPAN_MINUTES = 840;

    /** Minimum daily rest in minutes (art. 15a LL: 11 consecutive hours). */
    public const DAILY_REST_MINUTES = 660;

    /** Daily rest that can be granted once a week if the two-week average stays at 11 hours (art. 15a cpv. 2 LL). */
    public const REDUCED_DAILY_REST_MINUTES = 480;

    /** Maximum statutory overtime per day, in minutes (art. 12 cpv. 2 LL: 2 hours). */
    public const MAXIMUM_DAILY_OVERTIME_MINUTES = 120;

    /** Minimum duration of a work interruption counted as a break, in minutes. */
    public const MINIMUM_BREAK_MINUTES = 15;

    /** Start of night work (art. 10 and 16 LL: 23:00 - 06:00). */
    public const NIGHT_START_HOUR = 23;

    /** End of night work. */
    public const NIGHT_END_HOUR = 6;

    /**
     * The minimum break for a daily working time (art. 15 LL: 15 minutes above 5.5 hours, 30 above 7, 60 above 9).
     */
    public static function requiredBreakMinutes(int $workedMinutes): int
    {
        return match (true) {
            $workedMinutes > 540 => 60,
            $workedMinutes > 420 => 30,
            $workedMinutes > 330 => 15,
            default => 0,
        };
    }

    /**
     * The yearly cap of statutory overtime in minutes (art. 12 cpv. 2 LL: 170 hours with a 45-hour week, 140 with 50 hours).
     */
    public static function yearlyOvertimeCapMinutes(float|int|string $legalMaximumWeeklyHours): int
    {
        return (float) $legalMaximumWeeklyHours >= 50 ? 140 * 60 : 170 * 60;
    }

    /**
     * The minutes of an interval falling in the night period (23:00 - 06:00 local time).
     */
    public static function nightMinutes(DateTimeInterface $start, DateTimeInterface $end): int
    {
        $start = self::toLocal($start);
        $end = self::toLocal($end);
        $minutes = 0;
        $lastDay = $end->startOfDay();

        for ($day = $start->startOfDay()->subDay(); $day <= $lastDay; $day = $day->addDay()) {
            $minutes += self::overlapMinutes(
                $start,
                $end,
                $day->setTime(self::NIGHT_START_HOUR, 0),
                $day->addDay()->setTime(self::NIGHT_END_HOUR, 0),
            );
        }

        return $minutes;
    }

    /**
     * Whether an interval touches the Swiss legal Sunday (Saturday 23:00 - Sunday 23:00 local time, art. 18 LL).
     */
    public static function isSundayWork(DateTimeInterface $start, DateTimeInterface $end): bool
    {
        $start = self::toLocal($start);
        $end = self::toLocal($end);
        $lastDay = $end->startOfDay()->addDay();

        for ($day = $start->startOfDay()->subDay(); $day <= $lastDay; $day = $day->addDay()) {
            if ($day->dayOfWeek === CarbonImmutable::SUNDAY
                && self::overlapMinutes($start, $end, $day->subDay()->setTime(23, 0), $day->setTime(23, 0)) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The overlap of two intervals in minutes.
     */
    public static function overlapMinutes(
        DateTimeInterface $start,
        DateTimeInterface $end,
        DateTimeInterface $otherStart,
        DateTimeInterface $otherEnd,
    ): int {
        $from = $start > $otherStart ? $start : $otherStart;
        $to = $end < $otherEnd ? $end : $otherEnd;

        return $to > $from ? self::minutesBetween($from, $to) : 0;
    }

    /**
     * The minutes elapsed between two instants, rounded to the nearest minute (half away from zero). The difference
     * is taken on the instants, not on the wall clock: the 23-hour and 25-hour days of the clock change count what
     * was really worked.
     */
    public static function minutesBetween(DateTimeInterface $start, DateTimeInterface $end): int
    {
        $seconds = ($end->getTimestamp() - $start->getTimestamp())
            + ((int) $end->format('u') - (int) $start->format('u')) / 1_000_000;

        return (int) round($seconds / 60, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * An instant in local time.
     */
    public static function toLocal(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE);
    }

    /**
     * Midnight of a local day given as Y-m-d.
     */
    public static function localDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
    }

    /**
     * Parse a date and time received from a client: without an explicit offset it is local time.
     */
    public static function parseLocal(string $value): CarbonImmutable
    {
        return self::toLocal(CarbonImmutable::parse($value, self::TIMEZONE));
    }

    /**
     * Exact division with rounding half away from zero, the result of Math.Round on a decimal value.
     */
    public static function roundedDivision(int $numerator, int $denominator): int
    {
        $quotient = intdiv(abs($numerator) * 2 + abs($denominator), 2 * abs($denominator));

        return ($numerator < 0) !== ($denominator < 0) ? -$quotient : $quotient;
    }

    /**
     * A decimal value in hundredths, so that the rounding works on exact integers as with a C# decimal.
     */
    public static function hundredths(float|int|string $value): int
    {
        return (int) round((float) $value * 100);
    }
}
