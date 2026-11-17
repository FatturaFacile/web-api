<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Billing-cycle arithmetic and daily pro-rata calculation.
 *
 * Pro-rata is always computed on actual calendar days (inclusive of both ends):
 * factor = service days / days of the full cycle the service is a part of.
 */
final class BillingCycle
{
    public const ANNIVERSARY = 'anniversary';

    public const CALENDAR = 'calendar';

    public static function months(string $interval): int
    {
        return match ($interval) {
            'annual' => 12,
            'semiannual' => 6,
            'quarterly' => 3,
            default => 1,
        };
    }

    /**
     * Last day of the billing period starting on $start.
     * "calendar" aligns the period end to the month/quarter/half/year boundary (partial first period).
     */
    public static function periodEnd(CarbonInterface $start, string $interval, string $alignment = self::ANNIVERSARY): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start)->startOfDay();

        if ($alignment === self::CALENDAR) {
            return self::calendarBucket($start, $interval)[1];
        }

        return $start->addMonthsNoOverflow(self::months($interval))->subDay();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day of the calendar bucket containing $date
     */
    public static function calendarBucket(CarbonInterface $date, string $interval): array
    {
        $date = CarbonImmutable::instance($date)->startOfDay();

        return match (self::months($interval)) {
            12 => [$date->startOfYear(), $date->endOfYear()->startOfDay()],
            6 => $date->month <= 6
                ? [$date->startOfYear(), $date->setDate($date->year, 6, 30)]
                : [$date->setDate($date->year, 7, 1), $date->endOfYear()->startOfDay()],
            3 => [$date->startOfQuarter(), $date->endOfQuarter()->startOfDay()],
            default => [$date->startOfMonth(), $date->endOfMonth()->startOfDay()],
        };
    }

    public static function daysInclusive(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();

        return max(0, (int) $from->diffInDays($to, false) + 1);
    }

    /**
     * Daily pro-rata of a full-cycle amount for the service window inside the cycle.
     *
     * @return array{factor: float, service_days: int, cycle_days: int, amount: float}
     */
    public static function prorate(float $fullCycleAmount, CarbonInterface $serviceStart, CarbonInterface $serviceEnd, CarbonInterface $cycleStart, CarbonInterface $cycleEnd): array
    {
        $cycleDays = max(1, self::daysInclusive($cycleStart, $cycleEnd));
        $serviceStart = CarbonImmutable::instance($serviceStart)->startOfDay();
        $serviceEnd = CarbonImmutable::instance($serviceEnd)->startOfDay();

        // Clamp the service window to the cycle.
        if ($serviceStart->lt($cycleStart)) {
            $serviceStart = CarbonImmutable::instance($cycleStart)->startOfDay();
        }
        if ($serviceEnd->gt($cycleEnd)) {
            $serviceEnd = CarbonImmutable::instance($cycleEnd)->startOfDay();
        }

        $serviceDays = min($cycleDays, self::daysInclusive($serviceStart, $serviceEnd));
        $factor = $serviceDays / $cycleDays;

        return [
            'factor' => round($factor, 6),
            'service_days' => $serviceDays,
            'cycle_days' => $cycleDays,
            'amount' => round($fullCycleAmount * $factor, 2),
        ];
    }
}
