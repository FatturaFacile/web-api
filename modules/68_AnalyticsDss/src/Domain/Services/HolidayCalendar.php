<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\Services;

use DateTimeImmutable;

/**
 * Public holidays that move the working-day count of the calendar dimension: the federal Swiss holidays and the national
 * Italian ones. Cantonal and local holidays are not modeled.
 */
final class HolidayCalendar
{
    /** @var array<int, array{ch: array<string, true>, it: array<string, true>}> */
    private array $cache = [];

    public function isSwissHoliday(DateTimeImmutable $date): bool
    {
        return isset($this->holidays((int) $date->format('Y'))['ch'][$date->format('m-d').'|'.$date->format('Y')]);
    }

    public function isItalianHoliday(DateTimeImmutable $date): bool
    {
        return isset($this->holidays((int) $date->format('Y'))['it'][$date->format('m-d').'|'.$date->format('Y')]);
    }

    /**
     * Working days (Monday to Friday, no Swiss or Italian holiday) of the month of the date.
     */
    public function workingDaysInMonth(DateTimeImmutable $date, string $country = 'CH'): int
    {
        $count = 0;
        $first = $date->modify('first day of this month');

        for ($day = 0, $length = (int) $date->format('t'); $day < $length; $day++) {
            $current = $first->modify("+{$day} days");
            $holiday = strtoupper($country) === 'IT' ? $this->isItalianHoliday($current) : $this->isSwissHoliday($current);

            if ((int) $current->format('N') < 6 && ! $holiday) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{ch: array<string, true>, it: array<string, true>}
     */
    private function holidays(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $easter = $this->easter($year);
        $offset = static fn (int $days): string => $easter->modify(sprintf('%+d days', $days))->format('m-d');
        $fixed = static fn (string ...$days): array => $days;
        $mark = static function (array $monthDays) use ($year): array {
            $set = [];

            foreach ($monthDays as $monthDay) {
                $set[$monthDay.'|'.$year] = true;
            }

            return $set;
        };

        return $this->cache[$year] = [
            'ch' => $mark([...$fixed('01-01', '08-01', '12-25', '12-26'), $offset(-2), $offset(1), $offset(39), $offset(50)]),
            'it' => $mark([...$fixed('01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'), $offset(1)]),
        ];
    }

    /**
     * Gregorian Easter Sunday (anonymous algorithm of Meeus, Jones and Butcher).
     */
    private function easter(int $year): DateTimeImmutable
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

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
