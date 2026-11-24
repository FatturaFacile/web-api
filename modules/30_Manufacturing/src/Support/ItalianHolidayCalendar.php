<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Support;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;

/**
 * National public holidays of Italy plus the patron saint day of the main provincial capitals (the region code of a
 * working calendar is the province plate, e.g. RM, MI).
 */
final class ItalianHolidayCalendar
{
    /** Patron saint days by province plate: month, day, name. */
    private const array PatronSaints = [
        'RM' => [6, 29, 'Santi Pietro e Paolo'],
        'MI' => [12, 7, 'Sant\'Ambrogio'],
        'TO' => [6, 24, 'San Giovanni Battista'],
        'FI' => [6, 24, 'San Giovanni Battista'],
        'GE' => [6, 24, 'San Giovanni Battista'],
        'NA' => [9, 19, 'San Gennaro'],
        'BO' => [10, 4, 'San Petronio'],
        'PA' => [7, 15, 'Santa Rosalia'],
        'BA' => [12, 6, 'San Nicola'],
        'TS' => [11, 3, 'San Giusto'],
        'VR' => [5, 21, 'San Zeno'],
        'CA' => [10, 30, 'San Saturnino'],
        'CT' => [2, 5, 'Sant\'Agata'],
        'VE' => [4, 25, 'San Marco'],
    ];

    /**
     * Holidays of a year as date => name, ordered by date.
     *
     * @return array<string, string>
     */
    public static function forRegion(?string $province, int $year): array
    {
        $easter = SwissHolidayCalendar::easterSunday($year);
        $days = [
            sprintf('%04d-01-01', $year) => 'Capodanno',
            sprintf('%04d-01-06', $year) => 'Epifania',
            $easter->format('Y-m-d') => 'Pasqua',
            $easter->addDay()->format('Y-m-d') => 'Lunedì dell\'Angelo',
            sprintf('%04d-04-25', $year) => 'Festa della Liberazione',
            sprintf('%04d-05-01', $year) => 'Festa del lavoro',
            sprintf('%04d-06-02', $year) => 'Festa della Repubblica',
            sprintf('%04d-08-15', $year) => 'Ferragosto',
            sprintf('%04d-11-01', $year) => 'Ognissanti',
            sprintf('%04d-12-08', $year) => 'Immacolata Concezione',
            sprintf('%04d-12-25', $year) => 'Natale',
            sprintf('%04d-12-26', $year) => 'Santo Stefano',
        ];

        $saint = self::PatronSaints[strtoupper(trim((string) $province))] ?? null;
        if ($saint !== null) {
            $days[CarbonImmutable::create($year, $saint[0], $saint[1])->format('Y-m-d')] ??= $saint[2];
        }

        ksort($days);

        return $days;
    }
}
