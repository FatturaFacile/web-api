<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use Carbon\CarbonImmutable;
use Modules\LeaveManagement\Domain\Enums\SundayHolidayRule;
use Modules\LeaveManagement\Domain\ValueObjects\HolidayEntry;
use Modules\LeaveManagement\Exceptions\LeaveManagementException;

/**
 * Official statutory holiday catalog for:
 * - Switzerland (CH): Federal + all 26 Cantons (AG..ZH)
 * - Italy (IT): National holidays + configurable/preset municipal Patron Saints (Sant'Ambrogio Milan, SS. Pietro e Paolo Rome, etc.)
 *   plus Sunday-falling holiday rules (festività cadenti di domenica).
 */
final class HolidayCatalogService
{
    /** @var list<string> */
    public const array SWISS_CANTONS = [
        'AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE',
        'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH',
    ];

    /** @var array<string, array{month: int, day: int, name: string, province: string}> */
    public const array ITALIAN_PATRON_SAINTS = [
        'milano' => ['month' => 12, 'day' => 7, 'name' => "Sant'Ambrogio", 'province' => 'MI'],
        'roma' => ['month' => 6, 'day' => 29, 'name' => 'SS. Pietro e Paolo', 'province' => 'RM'],
        'torino' => ['month' => 6, 'day' => 24, 'name' => 'San Giovanni Battista', 'province' => 'TO'],
        'firenze' => ['month' => 6, 'day' => 24, 'name' => 'San Giovanni Battista', 'province' => 'FI'],
        'genova' => ['month' => 6, 'day' => 24, 'name' => 'San Giovanni Battista', 'province' => 'GE'],
        'napoli' => ['month' => 9, 'day' => 19, 'name' => 'San Gennaro', 'province' => 'NA'],
        'bologna' => ['month' => 10, 'day' => 4, 'name' => 'San Petronio', 'province' => 'BO'],
        'venezia' => ['month' => 4, 'day' => 25, 'name' => 'San Marco', 'province' => 'VE'],
        'verona' => ['month' => 5, 'day' => 21, 'name' => 'San Zeno', 'province' => 'VR'],
        'padova' => ['month' => 6, 'day' => 13, 'name' => "Sant'Antonio", 'province' => 'PD'],
        'trieste' => ['month' => 11, 'day' => 3, 'name' => 'San Giusto', 'province' => 'TS'],
        'palermo' => ['month' => 7, 'day' => 15, 'name' => 'Santa Rosalia', 'province' => 'PA'],
        'bari' => ['month' => 12, 'day' => 6, 'name' => 'San Nicola', 'province' => 'BA'],
        'como' => ['month' => 8, 'day' => 31, 'name' => "Sant'Abbondio", 'province' => 'CO'],
        'varese' => ['month' => 5, 'day' => 8, 'name' => 'San Vittore', 'province' => 'VA'],
        'bergamo' => ['month' => 8, 'day' => 26, 'name' => "Sant'Alessandro", 'province' => 'BG'],
        'brescia' => ['month' => 2, 'day' => 15, 'name' => 'SS. Faustino e Giovita', 'province' => 'BS'],
        'cagliari' => ['month' => 10, 'day' => 30, 'name' => 'San Saturnino', 'province' => 'CA'],
    ];

    private const array CH_BERCHTOLD = ['AG', 'BE', 'FR', 'GL', 'JU', 'LU', 'NE', 'OW', 'SH', 'SO', 'TG', 'VD', 'ZG', 'ZH'];

    private const array CH_EPIPHANY = ['SZ', 'TI', 'UR'];

    private const array CH_SAINT_JOSEPH = ['NW', 'SZ', 'TI', 'UR', 'VS'];

    private const array CH_WITHOUT_GOOD_FRIDAY = ['TI', 'VS'];

    private const array CH_WITHOUT_EASTER_MONDAY = ['VS'];

    private const array CH_LABOUR_DAY = ['BL', 'BS', 'JU', 'NE', 'SH', 'TG', 'TI', 'ZH'];

    private const array CH_CORPUS_CHRISTI = ['AI', 'FR', 'JU', 'LU', 'NW', 'OW', 'SO', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const array CH_ASSUMPTION = ['AI', 'FR', 'JU', 'LU', 'NW', 'OW', 'SO', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const array CH_ALL_SAINTS = ['AI', 'FR', 'GL', 'JU', 'LU', 'NW', 'OW', 'SG', 'SO', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const array CH_IMMACULATE_CONCEPTION = ['AI', 'FR', 'LU', 'NW', 'OW', 'SZ', 'TI', 'UR', 'VS', 'ZG'];

    private const array CH_WITHOUT_SAINT_STEPHEN = ['GE', 'JU', 'NE', 'VD', 'VS'];

    /**
     * @return list<HolidayEntry>
     */
    public function generateForJurisdiction(
        string $country,
        int $year,
        ?string $cantonOrProvinceCode = null,
        ?string $municipalityName = null,
        ?string $patronSaintMonthDay = null,
        ?string $patronSaintName = null,
        string $sundayRule = SundayHolidayRule::COMPENSATORY_PAY_OR_DAY->value,
    ): array {
        $upperCountry = strtoupper(trim($country));

        return match ($upperCountry) {
            'CH' => $this->generateSwiss($year, $cantonOrProvinceCode ?? 'TI', $sundayRule),
            'IT' => $this->generateItalian($year, $municipalityName, $patronSaintMonthDay, $patronSaintName, $sundayRule),
            default => throw LeaveManagementException::validation('unsupported_country', ['country' => $country]),
        };
    }

    /**
     * @return list<HolidayEntry>
     */
    public function generateSwiss(
        int $year,
        string $canton = 'TI',
        string $sundayRule = SundayHolidayRule::NONE->value,
    ): array {
        $code = strtoupper(trim($canton));
        if ($code === '') {
            $code = 'TI';
        }
        if (! in_array($code, self::SWISS_CANTONS, true)) {
            throw LeaveManagementException::validation('invalid_swiss_canton', ['canton' => $code]);
        }

        $easter = $this->easterSunday($year);

        /** @var list<array{CarbonImmutable, string, string}> $entries */
        $entries = [
            [$this->day($year, 1, 1), 'Capodanno / Neujahr / Nouvel An', 'federal'],
            [$easter->addDays(39), 'Ascensione / Auffahrt / Ascension', 'federal'],
            [$this->day($year, 8, 1), 'Festa nazionale svizzera / Bundesfeier / Fête nationale', 'federal'],
            [$this->day($year, 12, 25), 'Natale / Weihnachten / Noël', 'federal'],
        ];

        $addIf = static function (bool $condition, CarbonImmutable $date, string $name) use (&$entries): void {
            if ($condition) {
                $entries[] = [$date, $name, 'cantonal'];
            }
        };

        $addIf(in_array($code, self::CH_BERCHTOLD, true), $this->day($year, 1, 2), 'San Bertoldo / Berchtoldstag');
        $addIf(in_array($code, self::CH_EPIPHANY, true), $this->day($year, 1, 6), 'Epifania / Dreikönigstag');
        $addIf($code === 'NE', $this->day($year, 3, 1), 'Instauration de la République (NE)');
        $addIf(in_array($code, self::CH_SAINT_JOSEPH, true), $this->day($year, 3, 19), 'San Giuseppe / Josefstag');
        $addIf(! in_array($code, self::CH_WITHOUT_GOOD_FRIDAY, true), $easter->subDays(2), 'Venerdì Santo / Karfreitag / Vendredi Saint');
        $addIf(! in_array($code, self::CH_WITHOUT_EASTER_MONDAY, true), $easter->addDay(), 'Lunedì di Pasqua / Ostermontag / Lundi de Pâques');
        $addIf($code === 'GL', $this->naefelserFahrt($year, $easter), 'Näfelser Fahrt (GL)');
        $addIf(in_array($code, self::CH_LABOUR_DAY, true), $this->day($year, 5, 1), 'Festa del lavoro / Tag der Arbeit');
        $addIf(! in_array($code, self::CH_WITHOUT_EASTER_MONDAY, true), $easter->addDays(50), 'Lunedì di Pentecoste / Pfingstmontag');
        $addIf(in_array($code, self::CH_CORPUS_CHRISTI, true), $easter->addDays(60), 'Corpus Domini / Fronleichnam');
        $addIf($code === 'JU', $this->day($year, 6, 23), 'Commémoration du plébiscite jurassien (JU)');
        $addIf($code === 'TI', $this->day($year, 6, 29), 'SS. Pietro e Paolo (TI)');
        $addIf(in_array($code, self::CH_ASSUMPTION, true), $this->day($year, 8, 15), 'Assunzione / Mariä Himmelfahrt');
        $addIf($code === 'GE', $this->firstSundayOfSeptember($year)->addDays(4), 'Jeûne genevois (GE)');
        $addIf($code === 'VD', $this->firstSundayOfSeptember($year)->addDays(15), 'Lundi du Jeûne fédéral (VD)');
        $addIf($code === 'OW', $this->day($year, 9, 25), 'Bruderklausenfest / San Nicolao della Flüe (OW)');
        $addIf(in_array($code, self::CH_ALL_SAINTS, true), $this->day($year, 11, 1), 'Ognissanti / Allerheiligen');
        $addIf(in_array($code, self::CH_IMMACULATE_CONCEPTION, true), $this->day($year, 12, 8), 'Immacolata / Mariä Empfängnis');
        $addIf(! in_array($code, self::CH_WITHOUT_SAINT_STEPHEN, true), $this->day($year, 12, 26), 'Santo Stefano / Stephanstag');
        $addIf($code === 'GE', $this->day($year, 12, 31), 'Restauration de la République (GE)');

        return $this->finalizeEntries($entries, $sundayRule);
    }

    /**
     * @return list<HolidayEntry>
     */
    public function generateItalian(
        int $year,
        ?string $municipalityName = null,
        ?string $patronSaintMonthDay = null,
        ?string $patronSaintName = null,
        string $sundayRule = SundayHolidayRule::COMPENSATORY_PAY_OR_DAY->value,
    ): array {
        $easter = $this->easterSunday($year);

        /** @var list<array{CarbonImmutable, string, string}> $entries */
        $entries = [
            [$this->day($year, 1, 1), 'Capodanno', 'national'],
            [$this->day($year, 1, 6), 'Epifania', 'national'],
            [$easter, 'Pasqua', 'national'],
            [$easter->addDay(), "Lunedì dell'Angelo (Pasquetta)", 'national'],
            [$this->day($year, 4, 25), 'Festa della Liberazione', 'national'],
            [$this->day($year, 5, 1), 'Festa dei Lavoratori', 'national'],
            [$this->day($year, 6, 2), 'Festa della Repubblica', 'national'],
            [$this->day($year, 8, 15), 'Assunzione (Ferragosto)', 'national'],
            [$this->day($year, 11, 1), 'Ognissanti', 'national'],
            [$this->day($year, 12, 8), 'Immacolata Concezione', 'national'],
            [$this->day($year, 12, 25), 'Natale', 'national'],
            [$this->day($year, 12, 26), 'Santo Stefano', 'national'],
        ];

        $patron = $this->resolveItalianPatronSaint($year, $municipalityName, $patronSaintMonthDay, $patronSaintName);
        if ($patron !== null) {
            $entries[] = [$patron['date'], $patron['name'], 'patron_saint'];
        }

        return $this->finalizeEntries($entries, $sundayRule);
    }

    /**
     * Count working days between two inclusive dates excluding weekends and provided holiday dates.
     *
     * @param  list<string>  $holidayDates  Y-m-d strings
     * @param  list<int>  $workingIsoWeekdays  1 (Mon) .. 7 (Sun)
     */
    public function countWorkingDays(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $holidayDates = [],
        array $workingIsoWeekdays = [1, 2, 3, 4, 5],
    ): int {
        if ($to->lt($from)) {
            return 0;
        }

        $holidaySet = array_flip($holidayDates);
        $count = 0;
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->format('Y-m-d');
            if (in_array($cursor->dayOfWeekIso, $workingIsoWeekdays, true) && ! isset($holidaySet[$dateStr])) {
                $count++;
            }
            $cursor = $cursor->addDay();
        }

        return $count;
    }

    public function easterSunday(int $year): CarbonImmutable
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

        return $this->day($year, $month, $day);
    }

    /**
     * @return array{date: CarbonImmutable, name: string}|null
     */
    private function resolveItalianPatronSaint(
        int $year,
        ?string $municipalityName,
        ?string $patronSaintMonthDay,
        ?string $patronSaintName,
    ): ?array {
        if ($patronSaintMonthDay !== null && preg_match('/^(\d{2})-(\d{2})$/', trim($patronSaintMonthDay), $m) === 1) {
            $month = (int) $m[1];
            $day = (int) $m[2];
            if (! checkdate($month, $day, $year)) {
                throw LeaveManagementException::validation('invalid_patron_saint_date', ['date' => $patronSaintMonthDay]);
            }

            return [
                'date' => $this->day($year, $month, $day),
                'name' => $patronSaintName !== null && trim($patronSaintName) !== ''
                    ? trim($patronSaintName)
                    : 'Santo Patrono ('.($municipalityName ?? 'Comune').')',
            ];
        }

        if ($municipalityName !== null && trim($municipalityName) !== '') {
            $preset = self::ITALIAN_PATRON_SAINTS[mb_strtolower(trim($municipalityName))] ?? null;
            if ($preset !== null) {
                return [
                    'date' => $this->day($year, $preset['month'], $preset['day']),
                    'name' => 'Santo Patrono — '.$preset['name'],
                ];
            }
        }

        return null;
    }

    /**
     * @param  list<array{CarbonImmutable, string, string}>  $rawEntries
     * @return list<HolidayEntry>
     */
    private function finalizeEntries(array $rawEntries, string $sundayRule): array
    {
        usort($rawEntries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $allowSundayComp = $sundayRule !== SundayHolidayRule::NONE->value;

        return array_map(static function (array $entry) use ($allowSundayComp): HolidayEntry {
            $isSunday = $entry[0]->isSunday();

            return new HolidayEntry(
                date: $entry[0]->format('Y-m-d'),
                name: $entry[1],
                scope: $entry[2],
                isSunday: $isSunday,
                isHalfDay: false,
                paidCompensationApplicable: $isSunday && $allowSundayComp,
            );
        }, $rawEntries);
    }

    private function firstSundayOfSeptember(int $year): CarbonImmutable
    {
        $first = $this->day($year, 9, 1);

        return $first->addDays((CarbonImmutable::SUNDAY - $first->dayOfWeek + 7) % 7);
    }

    private function naefelserFahrt(int $year, CarbonImmutable $easter): CarbonImmutable
    {
        $first = $this->day($year, 4, 1);
        $thursday = $first->addDays((CarbonImmutable::THURSDAY - $first->dayOfWeek + 7) % 7);

        return $thursday > $easter->subDays(7) && $thursday < $easter ? $thursday->addDays(7) : $thursday;
    }

    private function day(int $year, int $month, int $dayOfMonth): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $dayOfMonth, 0, 0, 0, 'Europe/Zurich');
    }
}
