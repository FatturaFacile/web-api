<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;

/**
 * The nation-wide public holidays that attract a holiday surcharge. Cantonal and local holidays (a patron saint, for
 * example) are outside the calendar: a tenant adds them through its collective agreement.
 */
final class PublicHolidayCalendar
{
    /** @var array<string, array<string, true>> */
    private array $cache = [];

    public function isHoliday(Jurisdiction $jurisdiction, CarbonImmutable $localDate): bool
    {
        $year = $localDate->year;
        $key = $jurisdiction->value.$year;
        $this->cache[$key] ??= array_fill_keys($this->holidays($jurisdiction, $year), true);

        return isset($this->cache[$key][$localDate->format('Y-m-d')]);
    }

    /**
     * @return list<string> Y-m-d dates
     */
    public function holidays(Jurisdiction $jurisdiction, int $year): array
    {
        $easter = self::easterSunday($year);
        $format = static fn (CarbonImmutable $date): string => $date->format('Y-m-d');
        $fixed = static fn (int $month, int $day): string => sprintf('%04d-%02d-%02d', $year, $month, $day);

        return match ($jurisdiction) {
            Jurisdiction::Italy => [
                $fixed(1, 1), $fixed(1, 6), $format($easter->addDay()), $fixed(4, 25), $fixed(5, 1), $fixed(6, 2),
                $fixed(8, 15), $fixed(11, 1), $fixed(12, 8), $fixed(12, 25), $fixed(12, 26),
            ],
            Jurisdiction::Switzerland => [
                $fixed(1, 1), $format($easter->subDays(2)), $format($easter->addDay()), $format($easter->addDays(39)),
                $format($easter->addDays(50)), $fixed(8, 1), $fixed(12, 25), $fixed(12, 26),
            ],
        };
    }

    /**
     * Anonymous Gregorian algorithm (Meeus/Jones/Butcher): no dependency on the calendar extension.
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
