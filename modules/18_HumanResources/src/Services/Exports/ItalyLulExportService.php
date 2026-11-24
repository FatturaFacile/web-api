<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Exports;

use App\Models\User;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\WorkingTimeReport;
use Modules\HumanResources\Services\WorkingTimeCalculator;

/**
 * Generates the Libro Unico del Lavoro (LUL) extract required by Art. 39 D.L. 112/2008 and checks
 * the compliance rules of D.Lgs. 66/2003 for Italian-jurisdiction employees.
 *
 * Art. 7  D.Lgs. 66/2003 — 11-hour minimum daily rest.
 * Art. 4  D.Lgs. 66/2003 — 48-hour rolling average over a 4-month reference period.
 */
final class ItalyLulExportService
{
    /** Minimum rest between two working periods (Art. 7 D.Lgs. 66/2003). */
    private const int DAILY_REST_MINUTES = 660;

    /** Maximum average weekly hours over the reference period (Art. 4 D.Lgs. 66/2003). */
    private const int MAX_AVERAGE_WEEKLY_HOURS = 48;

    /** Reference period in months for the rolling 48-hour average (Art. 4 D.Lgs. 66/2003). */
    private const int REFERENCE_PERIOD_MONTHS = 4;

    public function __construct(private readonly WorkingTimeCalculator $calculator) {}

    /**
     * Build the LUL CSV for one employee and one month/period.
     *
     * @return array{content: string, filename: string, mime: string}
     */
    public function exportCsv(User $employee, string $from, string $to): array
    {
        $report = $this->calculator->evaluate($employee, $from, $to);
        $rows = $this->buildRows($employee, $report);
        $content = $this->toCsv($rows);

        return [
            'content' => $content,
            'filename' => sprintf('lul_%s_%s_%s.csv', $employee->id, $from, $to),
            'mime' => 'text/csv; charset=UTF-8',
        ];
    }

    /**
     * Build the Zucchetti fixed-width export (600-byte records per day).
     *
     * @return array{content: string, filename: string, mime: string}
     */
    public function exportZucchetti(User $employee, string $from, string $to): array
    {
        $report = $this->calculator->evaluate($employee, $from, $to);
        $rows = $this->buildRows($employee, $report);
        $content = $this->toZucchetti($rows);

        return [
            'content' => $content,
            'filename' => sprintf('lul_%s_%s_%s.txt', $employee->id, $from, $to),
            'mime' => 'text/plain; charset=UTF-8',
        ];
    }

    /**
     * Return all Art. 7 and Art. 4 compliance violations for the period.
     *
     * @return array<int, array{type: string, date: string, detail: array<string, mixed>}>
     */
    public function complianceAlerts(User $employee, string $from, string $to): array
    {
        $report = $this->calculator->evaluate($employee, $from, $to);

        return [
            ...$this->dailyRestViolations($report),
            ...$this->weeklyAverageViolations($employee, $from, $to, $report),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(User $employee, WorkingTimeReport $report): array
    {
        $matricola = substr((string) $employee->id, 0, 10);
        $lastName = $employee->last_name ?? '';
        $firstName = $employee->first_name ?? '';

        $rows = [];

        foreach ($report->days as $day) {
            if ($day->workedMinutes === 0 && $day->creditedMinutes === 0) {
                continue;
            }

            $worked = $day->workedMinutes + $day->creditedMinutes;
            $balance = max(0, $day->balanceMinutes());
            $rows[] = [
                'matricola' => $matricola,
                'cognome' => $lastName,
                'nome' => $firstName,
                'data' => $day->date->format('d/m/Y'),
                'ore_lavorate' => $this->minutesToHours($worked),
                'ore_supplementari' => $this->minutesToHours(min($balance, 120)),
                'ore_straordinarie' => $this->minutesToHours(max(0, $balance - 120)),
                'riposo_min' => $day->restBeforeMinutes,
                'riposo_ok' => $day->restBeforeMinutes === null || $day->restBeforeMinutes >= self::DAILY_REST_MINUTES ? 'S' : 'N',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $lines = [implode(';', array_keys($rows[0]))];

        foreach ($rows as $row) {
            $cells = array_map(static function (mixed $v): string {
                $s = (string) ($v ?? '');

                return str_contains($s, ';') ? '"'.$s.'"' : $s;
            }, $row);
            $lines[] = implode(';', $cells);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Zucchetti CEF fixed-width layout (one 120-char record per worked day).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function toZucchetti(array $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $line = ''
                .str_pad((string) ($row['matricola'] ?? ''), 10)
                .str_pad((string) ($row['cognome'] ?? ''), 30)
                .str_pad((string) ($row['nome'] ?? ''), 20)
                .str_pad((string) ($row['data'] ?? ''), 10)
                .str_pad(number_format((float) ($row['ore_lavorate'] ?? 0), 2, '.', ''), 7, ' ', STR_PAD_LEFT)
                .str_pad(number_format((float) ($row['ore_supplementari'] ?? 0), 2, '.', ''), 7, ' ', STR_PAD_LEFT)
                .str_pad(number_format((float) ($row['ore_straordinarie'] ?? 0), 2, '.', ''), 7, ' ', STR_PAD_LEFT)
                .str_pad((string) ($row['riposo_ok'] ?? ''), 1);
            $lines[] = str_pad($line, 120);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Art. 7 D.Lgs. 66/2003: flag every day where the rest since the previous day is under 11 hours.
     *
     * @return array<int, array{type: string, date: string, detail: array<string, mixed>}>
     */
    private function dailyRestViolations(WorkingTimeReport $report): array
    {
        $violations = [];

        foreach ($report->days as $day) {
            if ($day->restBeforeMinutes !== null && $day->restBeforeMinutes < self::DAILY_REST_MINUTES) {
                $violations[] = [
                    'type' => 'it_daily_rest_violation',
                    'date' => $day->date->format('Y-m-d'),
                    'detail' => [
                        'rest_minutes' => $day->restBeforeMinutes,
                        'threshold_minutes' => self::DAILY_REST_MINUTES,
                        'article' => 'Art. 7 D.Lgs. 66/2003',
                    ],
                ];
            }
        }

        return $violations;
    }

    /**
     * Art. 4 D.Lgs. 66/2003: rolling 4-month average must not exceed 48 h/week.
     * We re-evaluate a wider window (reference period start → period end) and check each week.
     *
     * @return array<int, array{type: string, date: string, detail: array<string, mixed>}>
     */
    private function weeklyAverageViolations(User $employee, string $from, string $to, WorkingTimeReport $report): array
    {
        $periodStart = SwissWorkingTimeRules::localDate($from)
            ->subMonths(self::REFERENCE_PERIOD_MONTHS)
            ->startOfWeek();

        $extendedReport = $this->calculator->evaluate(
            $employee,
            $periodStart->format('Y-m-d'),
            $to,
        );

        $weekBuckets = [];

        foreach ($extendedReport->days as $day) {
            $weekKey = $day->date->startOfWeek()->format('Y-m-d');
            $weekBuckets[$weekKey] = ($weekBuckets[$weekKey] ?? 0) + $day->workedMinutes;
        }

        $keys = array_keys($weekBuckets);
        $violations = [];

        foreach ($keys as $i => $weekKey) {
            $start = max(0, $i - (self::REFERENCE_PERIOD_MONTHS * 4) + 1);
            $window = array_slice($weekBuckets, $start, $i - $start + 1, true);
            $count = count($window);

            if ($count === 0) {
                continue;
            }

            $avgMinutes = (int) round(array_sum($window) / $count);
            $avgHours = $avgMinutes / 60;

            if ($avgHours > self::MAX_AVERAGE_WEEKLY_HOURS) {
                $violations[] = [
                    'type' => 'it_48h_average_violation',
                    'date' => $weekKey,
                    'detail' => [
                        'average_weekly_hours' => round($avgHours, 2),
                        'threshold_hours' => self::MAX_AVERAGE_WEEKLY_HOURS,
                        'reference_weeks' => $count,
                        'article' => 'Art. 4 D.Lgs. 66/2003',
                    ],
                ];
            }
        }

        return $violations;
    }

    private function minutesToHours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }
}
