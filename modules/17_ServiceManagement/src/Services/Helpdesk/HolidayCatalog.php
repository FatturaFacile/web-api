<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Carbon\CarbonImmutable;

/**
 * Public holidays of Switzerland (federal plus cantonal) and Italy (national plus regional observances) for the SLA
 * clock. A business hours calendar names a country and optionally a region (CH canton code such as "TI" or "ZH", IT region
 * code such as "LOM" for Lombardy); the clock skips those days. Movable feasts derive from Easter.
 */
final class HolidayCatalog
{
    public const array Countries = ['CH', 'IT'];

    /**
     * Cantons with their own holiday profile; every other canton gets the common Swiss profile.
     *
     * @var list<string>
     */
    public const array SwissCantons = ['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH'];

    /**
     * @var list<string>
     */
    public const array ItalianRegions = ['ABR', 'BAS', 'CAL', 'CAM', 'EMR', 'FVG', 'LAZ', 'LIG', 'LOM', 'MAR', 'MOL', 'PIE', 'PUG', 'SAR', 'SIC', 'TAA', 'TOS', 'UMB', 'VDA', 'VEN'];

    public function supports(?string $country, ?string $region = null): bool
    {
        if ($country === null || ! in_array(strtoupper($country), self::Countries, true)) {
            return false;
        }

        if ($region === null || $region === '') {
            return true;
        }

        return strtoupper($country) === 'CH'
            ? in_array(strtoupper($region), self::SwissCantons, true)
            : in_array(strtoupper($region), self::ItalianRegions, true);
    }

    /**
     * Holiday dates (Y-m-d) of the years first..last inclusive.
     *
     * @return list<string>
     */
    public function between(string $country, ?string $region, int $firstYear, int $lastYear): array
    {
        $dates = [];

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            foreach ($this->forYear($country, $region, $year) as $date) {
                $dates[$date] = $date;
            }
        }

        return array_values($dates);
    }

    /**
     * @return list<string>
     */
    public function forYear(string $country, ?string $region, int $year): array
    {
        $country = strtoupper($country);
        $region = $region === null || $region === '' ? null : strtoupper($region);

        $dates = match ($country) {
            'CH' => $this->swiss($year, $region),
            'IT' => $this->italian($year, $region),
            default => [],
        };

        $dates = array_unique($dates);
        sort($dates);

        return array_values($dates);
    }

    /**
     * Easter Sunday of a year (Meeus/Jones/Butcher algorithm of the Gregorian calendar).
     */
    public function easter(int $year): CarbonImmutable
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

    /**
     * @return list<string>
     */
    private function swiss(int $year, ?string $canton): array
    {
        $easter = $this->easter($year);
        $fixed = fn (int $month, int $day): string => sprintf('%04d-%02d-%02d', $year, $month, $day);
        $moving = static fn (int $offset): string => $easter->addDays($offset)->format('Y-m-d');

        // Federal: New Year, Ascension, National Day and Christmas are observed practically everywhere.
        $dates = [$fixed(1, 1), $moving(39), $fixed(8, 1), $fixed(12, 25)];

        // Common cantonal profile: Good Friday, Easter Monday, Whit Monday and Boxing Day.
        $common = [$moving(-2), $moving(1), $moving(50), $fixed(12, 26)];

        return match ($canton) {
            null => [...$dates, ...$common],
            // Ticino: Epiphany, St Joseph, Corpus Christi, Sts Peter and Paul, Assumption, All Saints, Immaculate Conception, Labour Day.
            'TI' => [...$dates, $moving(-2), $moving(1), $moving(50), $fixed(12, 26), $fixed(1, 6), $fixed(3, 19), $moving(60), $fixed(6, 29), $fixed(8, 15), $fixed(11, 1), $fixed(12, 8), $fixed(5, 1)],
            // Zurich: Berchtoldstag, Sechseläuten is a local half day (not counted), Labour Day, Knabenschiessen is not a holiday.
            'ZH' => [...$dates, ...$common, $fixed(1, 2), $fixed(5, 1)],
            // Geneva: Jeûne genevois (Thursday after the first Sunday of September) and Restoration of the Republic; no Whit Monday gap.
            'GE' => [...$dates, ...$common, $this->thursdayAfterFirstSunday($year, 9), $fixed(12, 31)],
            // Vaud: Berchtoldstag and Jeûne fédéral Monday (Monday after the third Sunday of September).
            'VD' => [...$dates, ...$common, $fixed(1, 2), $this->mondayAfterThirdSunday($year, 9)],
            // Catholic cantons: Corpus Christi, Assumption, All Saints, Immaculate Conception.
            'LU', 'UR', 'SZ', 'OW', 'NW', 'ZG', 'FR', 'SO', 'AI', 'VS', 'JU' => [...$dates, ...$common, $moving(60), $fixed(8, 15), $fixed(11, 1), $fixed(12, 8)],
            default => [...$dates, ...$common],
        };
    }

    /**
     * @return list<string>
     */
    private function italian(int $year, ?string $region): array
    {
        $easter = $this->easter($year);
        $fixed = fn (int $month, int $day): string => sprintf('%04d-%02d-%02d', $year, $month, $day);

        $dates = [
            $fixed(1, 1), $fixed(1, 6), $easter->addDay()->format('Y-m-d'), $fixed(4, 25), $fixed(5, 1),
            $fixed(6, 2), $fixed(8, 15), $fixed(11, 1), $fixed(12, 8), $fixed(12, 25), $fixed(12, 26),
        ];

        return match ($region) {
            // Lombardy: Sant'Ambrogio, patron of Milan, the regional capital, is a day off for the local offices.
            'LOM' => [...$dates, $fixed(12, 7)],
            // Lazio: Sts Peter and Paul, patrons of Rome.
            'LAZ' => [...$dates, $fixed(6, 29)],
            // Campania: San Gennaro, patron of Naples.
            'CAM' => [...$dates, $fixed(9, 19)],
            // Sicily: Santa Rosalia, patron of Palermo.
            'SIC' => [...$dates, $fixed(7, 15)],
            // Piedmont: San Giovanni Battista, patron of Turin.
            'PIE' => [...$dates, $fixed(6, 24)],
            default => $dates,
        };
    }

    private function thursdayAfterFirstSunday(int $year, int $month): string
    {
        $firstSunday = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->next(CarbonImmutable::SUNDAY);
        $day = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');

        if ($day->isSunday()) {
            $firstSunday = $day;
        }

        return $firstSunday->addDays(4)->format('Y-m-d');
    }

    private function mondayAfterThirdSunday(int $year, int $month): string
    {
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
        $firstSunday = $first->isSunday() ? $first : $first->next(CarbonImmutable::SUNDAY);

        return $firstSunday->addWeeks(2)->addDay()->format('Y-m-d');
    }
}
