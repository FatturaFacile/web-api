<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Public holidays the business calendars (and so the SLA, reminder and escalation clocks) skip: Swiss federal and
 * cantonal days, Italian national days and a configurable patron saint. Pure calculation, no database: the seeder and the
 * `seed-holidays` endpoint both write its result into `business_calendar_holidays`.
 *
 * Swiss days are those observed in most cantons; the cantonal list adds what a canton observes on top (the lists are the
 * commonly observed full-day holidays and are meant as a starting point the administrator can edit).
 */
final class HolidayCalendarFactory
{
    /**
     * Patron saint days of Italian cities as `MM-DD` and the saint's name.
     *
     * @var array<string, array{date: string, name: string}>
     */
    public const array PatronSaints = [
        'roma' => ['date' => '06-29', 'name' => 'Santi Pietro e Paolo'],
        'milano' => ['date' => '12-07', 'name' => 'Sant\'Ambrogio'],
        'torino' => ['date' => '06-24', 'name' => 'San Giovanni Battista'],
        'firenze' => ['date' => '06-24', 'name' => 'San Giovanni Battista'],
        'genova' => ['date' => '06-24', 'name' => 'San Giovanni Battista'],
        'napoli' => ['date' => '09-19', 'name' => 'San Gennaro'],
        'bologna' => ['date' => '10-04', 'name' => 'San Petronio'],
        'palermo' => ['date' => '07-15', 'name' => 'Santa Rosalia'],
        'bari' => ['date' => '12-06', 'name' => 'San Nicola'],
        'venezia' => ['date' => '04-25', 'name' => 'San Marco'],
        'verona' => ['date' => '05-21', 'name' => 'San Zeno'],
        'trieste' => ['date' => '11-03', 'name' => 'San Giusto'],
        'cagliari' => ['date' => '10-30', 'name' => 'San Saturnino'],
    ];

    /**
     * Holidays of one year.
     *
     * @param  string  $country  CH or IT
     * @param  ?string  $canton  Swiss canton code (ZH, BE, LU, TI, GE, VD, BS, AG)
     * @param  ?string  $patronSaint  an Italian city key of {@see PatronSaints} or a literal `MM-DD`
     * @return list<array{date: string, name: string, type: string, region: ?string}>
     *
     * @throws InvalidArgumentException for an unsupported country
     */
    public function holidays(string $country, int $year, ?string $canton = null, ?string $patronSaint = null): array
    {
        $holidays = match (strtoupper($country)) {
            'CH' => $this->swiss($year, $canton === null ? null : strtoupper($canton)),
            'IT' => $this->italian($year, $patronSaint),
            default => throw new InvalidArgumentException("Holidays are available for CH and IT only, [{$country}] given."),
        };

        usort($holidays, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        // One entry per day: a cantonal day that falls on a national day does not appear twice.
        $unique = [];
        foreach ($holidays as $holiday) {
            $unique[$holiday['date']] ??= $holiday;
        }

        return array_values($unique);
    }

    /**
     * Easter Sunday of a year (Meeus/Jones/Butcher algorithm).
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

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /**
     * @return list<array{date: string, name: string, type: string, region: ?string}>
     */
    private function swiss(int $year, ?string $canton): array
    {
        $easter = $this->easter($year);
        $holidays = [
            $this->day($year, '01-01', 'New Year\'s Day', 'national', 'CH'),
            $this->at($easter->subDays(2), 'Good Friday', 'national', 'CH'),
            $this->at($easter->addDay(), 'Easter Monday', 'national', 'CH'),
            $this->at($easter->addDays(39), 'Ascension Day', 'national', 'CH'),
            $this->at($easter->addDays(50), 'Whit Monday', 'national', 'CH'),
            $this->day($year, '08-01', 'Swiss National Day', 'national', 'CH'),
            $this->day($year, '12-25', 'Christmas Day', 'national', 'CH'),
            $this->day($year, '12-26', 'St. Stephen\'s Day', 'national', 'CH'),
        ];

        $cantonal = match ($canton) {
            'ZH' => [$this->day($year, '01-02', 'Berchtold\'s Day', 'cantonal', 'ZH'), $this->day($year, '05-01', 'Labour Day', 'cantonal', 'ZH')],
            'BE' => [$this->day($year, '01-02', 'Berchtold\'s Day', 'cantonal', 'BE')],
            'AG' => [$this->day($year, '01-02', 'Berchtold\'s Day', 'cantonal', 'AG')],
            'BS' => [$this->day($year, '05-01', 'Labour Day', 'cantonal', 'BS')],
            'LU' => [$this->at($easter->addDays(60), 'Corpus Christi', 'cantonal', 'LU'), $this->day($year, '08-15', 'Assumption Day', 'cantonal', 'LU'), $this->day($year, '11-01', 'All Saints\' Day', 'cantonal', 'LU'), $this->day($year, '12-08', 'Immaculate Conception', 'cantonal', 'LU')],
            'TI' => [$this->day($year, '01-06', 'Epiphany', 'cantonal', 'TI'), $this->day($year, '03-19', 'St. Joseph\'s Day', 'cantonal', 'TI'), $this->at($easter->addDays(60), 'Corpus Christi', 'cantonal', 'TI'), $this->day($year, '06-29', 'Saints Peter and Paul', 'cantonal', 'TI'), $this->day($year, '08-15', 'Assumption Day', 'cantonal', 'TI'), $this->day($year, '11-01', 'All Saints\' Day', 'cantonal', 'TI'), $this->day($year, '12-08', 'Immaculate Conception', 'cantonal', 'TI')],
            'GE' => [$this->at($this->nthWeekday($year, 9, 0, 1)->addDays(4), 'Jeûne genevois', 'cantonal', 'GE'), $this->day($year, '12-31', 'Restauration de la République', 'cantonal', 'GE')],
            'VD' => [$this->day($year, '01-02', 'Berchtold\'s Day', 'cantonal', 'VD'), $this->at($this->nthWeekday($year, 9, 0, 3)->addDay(), 'Lundi du Jeûne', 'cantonal', 'VD')],
            default => [],
        };

        return [...$holidays, ...$cantonal];
    }

    /**
     * @return list<array{date: string, name: string, type: string, region: ?string}>
     */
    private function italian(int $year, ?string $patronSaint): array
    {
        $easter = $this->easter($year);
        $holidays = [
            $this->day($year, '01-01', 'Capodanno', 'national', 'IT'),
            $this->day($year, '01-06', 'Epifania', 'national', 'IT'),
            $this->at($easter, 'Pasqua', 'national', 'IT'),
            $this->at($easter->addDay(), 'Lunedì dell\'Angelo', 'national', 'IT'),
            $this->day($year, '04-25', 'Festa della Liberazione', 'national', 'IT'),
            $this->day($year, '05-01', 'Festa del Lavoro', 'national', 'IT'),
            $this->day($year, '06-02', 'Festa della Repubblica', 'national', 'IT'),
            $this->day($year, '08-15', 'Ferragosto', 'national', 'IT'),
            $this->day($year, '11-01', 'Ognissanti', 'national', 'IT'),
            $this->day($year, '12-08', 'Immacolata Concezione', 'national', 'IT'),
            $this->day($year, '12-25', 'Natale', 'national', 'IT'),
            $this->day($year, '12-26', 'Santo Stefano', 'national', 'IT'),
        ];

        if ($patronSaint !== null && $patronSaint !== '') {
            $key = strtolower($patronSaint);

            if (isset(self::PatronSaints[$key])) {
                $holidays[] = $this->day($year, self::PatronSaints[$key]['date'], self::PatronSaints[$key]['name'], 'patron_saint', strtoupper($key));
            } elseif (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $patronSaint) === 1) {
                $holidays[] = $this->day($year, $patronSaint, 'Patron saint', 'patron_saint', null);
            }
        }

        return $holidays;
    }

    /**
     * The n-th occurrence (1-based) of a weekday (0 = Sunday) in a month.
     */
    private function nthWeekday(int $year, int $month, int $weekday, int $n): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $offset = ($weekday - $first->dayOfWeek + 7) % 7;

        return $first->addDays($offset + 7 * ($n - 1));
    }

    /**
     * @return array{date: string, name: string, type: string, region: ?string}
     */
    private function day(int $year, string $monthDay, string $name, string $type, ?string $region): array
    {
        return ['date' => sprintf('%04d-%s', $year, $monthDay), 'name' => $name, 'type' => $type, 'region' => $region];
    }

    /**
     * @return array{date: string, name: string, type: string, region: ?string}
     */
    private function at(CarbonImmutable $date, string $name, string $type, ?string $region): array
    {
        return ['date' => $date->toDateString(), 'name' => $name, 'type' => $type, 'region' => $region];
    }
}
