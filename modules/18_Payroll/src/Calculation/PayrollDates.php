<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Calendar arithmetic with the semantics of the .NET DateTime: adding months or years stops at the last day of the
 * target month instead of overflowing into the next one.
 */
final class PayrollDates
{
    public static function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($date, 0, 10).' 00:00:00', new DateTimeZone('UTC'));
    }

    public static function from(DateTimeInterface $date): DateTimeImmutable
    {
        return self::date($date->format('Y-m-d'));
    }

    public static function monthStart(int $year, int $month): DateTimeImmutable
    {
        return self::date(sprintf('%04d-%02d-01', $year, $month));
    }

    public static function monthEnd(int $year, int $month): DateTimeImmutable
    {
        return self::addDays(self::addMonths(self::monthStart($year, $month), 1), -1);
    }

    public static function addDays(DateTimeImmutable $date, int $days): DateTimeImmutable
    {
        return $date->modify(sprintf('%+d days', $days));
    }

    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $total = ((int) $date->format('Y')) * 12 + ((int) $date->format('n')) - 1 + $months;
        $year = (int) floor($total / 12);
        $month = $total - ($year * 12) + 1;
        $lastDay = (int) self::date(sprintf('%04d-%02d-01', $year, $month))->format('t');

        return self::date(sprintf('%04d-%02d-%02d', $year, $month, min((int) $date->format('j'), $lastDay)));
    }

    public static function addYears(DateTimeImmutable $date, int $years): DateTimeImmutable
    {
        return self::addMonths($date, $years * 12);
    }

    public static function ageAt(DateTimeImmutable $birth, DateTimeImmutable $date): int
    {
        $age = (int) $date->format('Y') - (int) $birth->format('Y');

        return self::addYears($birth, $age) > $date ? $age - 1 : $age;
    }
}
