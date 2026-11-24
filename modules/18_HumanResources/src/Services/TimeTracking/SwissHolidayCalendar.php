<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * Public holidays of the Swiss cantons (the national day plus the cantonal holidays equal to Sundays, art. 20a LL).
 * The lists cover the holidays valid on the whole territory of each canton; holidays observed only in some
 * municipalities or as half days are added or corrected in the time_public_holidays table.
 */
final class SwissHolidayCalendar
{
    public const DEFAULT_CANTON = 'TI';

    public const CANTONS = ['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH'];

    private const BERCHTOLD = ['AG', 'BE', 'FR', 'GL', 'JU', 'LU', 'NE', 'OW', 'SH', 'SO', 'TG', 'VD', 'ZG', 'ZH'];

    private const EPIPHANY = ['SZ', 'TI', 'UR'];

    private const SAINT_JOSEPH = ['NW', 'SZ', 'TI', 'UR', 'VS'];

    private const WITHOUT_GOOD_FRIDAY = ['TI', 'VS'];

    private const WITHOUT_EASTER_MONDAY = ['VS'];

    private const LABOUR_DAY = ['BL', 'BS', 'JU', 'NE', 'SH', 'TG', 'TI', 'ZH'];

    private const CORPUS_CHRISTI = ['AI', 'FR', 'JU', 'LU', 'NW', 'OW', 'SO', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const ASSUMPTION = ['AI', 'FR', 'JU', 'LU', 'NW', 'OW', 'SO', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const ALL_SAINTS = ['AI', 'FR', 'GL', 'JU', 'LU', 'NW', 'OW', 'SG', 'SO', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const IMMACULATE_CONCEPTION = ['AI', 'FR', 'LU', 'NW', 'OW', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const WITHOUT_SAINT_STEPHEN = ['GE', 'JU', 'NE', 'VD', 'VS'];

    /**
     * The public holidays of a canton (Ticino when empty), ordered by date.
     *
     * @return array<int, HolidayEntry>
     */
    public static function forCanton(?string $canton, int $year): array
    {
        $code = strtoupper(trim((string) $canton));
        $code = $code === '' ? self::DEFAULT_CANTON : $code;
        $easter = self::easterSunday($year);

        /** @var array<int, array{CarbonImmutable, string}> $days */
        $days = [
            [self::day($year, 1, 1), 'Capodanno'],
            [$easter->addDays(39), 'Ascensione'],
            [self::day($year, 8, 1), 'Festa nazionale'],
            [self::day($year, 12, 25), 'Natale'],
        ];

        $addIf = static function (bool $condition, CarbonImmutable $date, string $name) use (&$days): void {
            if ($condition) {
                $days[] = [$date, $name];
            }
        };

        $addIf(in_array($code, self::BERCHTOLD, true), self::day($year, 1, 2), 'San Bertoldo');
        $addIf(in_array($code, self::EPIPHANY, true), self::day($year, 1, 6), 'Epifania');
        $addIf($code === 'NE', self::day($year, 3, 1), 'Instaurazione della Repubblica');
        $addIf(in_array($code, self::SAINT_JOSEPH, true), self::day($year, 3, 19), 'San Giuseppe');
        $addIf(! in_array($code, self::WITHOUT_GOOD_FRIDAY, true), $easter->subDays(2), 'Venerdì Santo');
        $addIf(! in_array($code, self::WITHOUT_EASTER_MONDAY, true), $easter->addDay(), 'Lunedì di Pasqua');
        $addIf($code === 'GL', self::naefelserFahrt($year, $easter), 'Näfelser Fahrt');
        $addIf(in_array($code, self::LABOUR_DAY, true), self::day($year, 5, 1), 'Festa del lavoro');
        $addIf(! in_array($code, self::WITHOUT_EASTER_MONDAY, true), $easter->addDays(50), 'Lunedì di Pentecoste');
        $addIf(in_array($code, self::CORPUS_CHRISTI, true), $easter->addDays(60), 'Corpus Domini');
        $addIf($code === 'JU', self::day($year, 6, 23), 'Plebiscito giurassiano');
        $addIf($code === 'TI', self::day($year, 6, 29), 'SS. Pietro e Paolo');
        $addIf(in_array($code, self::ASSUMPTION, true), self::day($year, 8, 15), 'Assunzione');
        $addIf($code === 'GE', self::firstSundayOfSeptember($year)->addDays(4), 'Digiuno ginevrino');
        $addIf($code === 'VD', self::firstSundayOfSeptember($year)->addDays(15), 'Lunedì del Digiuno federale');
        $addIf($code === 'OW', self::day($year, 9, 25), 'San Nicolao della Flüe');
        $addIf(in_array($code, self::ALL_SAINTS, true), self::day($year, 11, 1), 'Ognissanti');
        $addIf(in_array($code, self::IMMACULATE_CONCEPTION, true), self::day($year, 12, 8), 'Immacolata');
        $addIf(! in_array($code, self::WITHOUT_SAINT_STEPHEN, true), self::day($year, 12, 26), 'Santo Stefano');
        $addIf($code === 'GE', self::day($year, 12, 31), 'Restaurazione della Repubblica');

        usort($days, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return array_map(static fn (array $day): HolidayEntry => new HolidayEntry($day[0]->format('Y-m-d'), $day[1]), $days);
    }

    /**
     * Easter Sunday (Gregorian calendar, anonymous algorithm).
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
        $h = ((19 * $a) + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + (2 * $e) + (2 * $i) - $h - $k) % 7;
        $m = intdiv($a + (11 * $h) + (22 * $l), 451);
        $month = intdiv($h + $l - (7 * $m) + 114, 31);
        $day = (($h + $l - (7 * $m) + 114) % 31) + 1;

        return self::day($year, $month, $day);
    }

    private static function firstSundayOfSeptember(int $year): CarbonImmutable
    {
        $first = self::day($year, 9, 1);

        return $first->addDays((CarbonImmutable::SUNDAY - $first->dayOfWeek + 7) % 7);
    }

    private static function naefelserFahrt(int $year, CarbonImmutable $easter): CarbonImmutable
    {
        $first = self::day($year, 4, 1);
        $thursday = $first->addDays((CarbonImmutable::THURSDAY - $first->dayOfWeek + 7) % 7);

        // Moved by one week when it falls in Holy Week.
        return $thursday > $easter->subDays(7) && $thursday < $easter ? $thursday->addDays(7) : $thursday;
    }

    private static function day(int $year, int $month, int $dayOfMonth): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $dayOfMonth, 0, 0, 0, SwissWorkingTimeRules::TIMEZONE);
    }
}
