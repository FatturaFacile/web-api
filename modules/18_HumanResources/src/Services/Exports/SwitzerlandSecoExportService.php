<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Exports;

use App\Models\User;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\TimeTracking\TimeRecordingMode;
use Modules\HumanResources\Services\TimeTracking\WorkingDayResult;
use Modules\HumanResources\Services\TimeTracking\WorkingTimeReport;
use Modules\HumanResources\Services\WorkingTimeCalculator;

/**
 * Generates the SECO-compliant working time extract and validates break obligations for
 * Swiss-jurisdiction employees under OLL 1 (Ordinance 1 to the Labour Act).
 *
 * Art. 73      OLL 1 — Detailed recording (Detailed mode).
 * Art. 73a     OLL 1 — Waiver for employees with broad autonomy (Waived mode).
 * Art. 73b     OLL 1 — Simplified recording for high-earners (Simplified mode).
 *
 * Break obligations (LL Art. 15 / OLL 1 Art. 18):
 *   >  5.5 h worked → 15-minute break required.
 *   >  7.0 h worked → 30-minute break required.
 *   >  9.0 h worked → 60-minute break required.
 */
final class SwitzerlandSecoExportService
{
    /** @var array<array{threshold_minutes: int, required_minutes: int}> Break thresholds (LL Art. 15). */
    private const array BREAK_THRESHOLDS = [
        ['threshold_minutes' => 540, 'required_minutes' => 60],
        ['threshold_minutes' => 420, 'required_minutes' => 30],
        ['threshold_minutes' => 330, 'required_minutes' => 15],
    ];

    /** Supplemental hours cap per week before statutory overtime starts (Mehrstunden vs Überstunden). */
    private const int SUPPLEMENTAL_WEEKLY_MINUTES = 120;

    public function __construct(private readonly WorkingTimeCalculator $calculator) {}

    /**
     * Build the SECO CSV for one employee and one month/period.
     *
     * @return array{content: string, filename: string, mime: string}
     */
    public function exportCsv(User $employee, string $from, string $to): array
    {
        $profile = TimeRecordingProfile::query()->where('user_id', $employee->id)->first();
        $mode = $profile->mode ?? TimeRecordingMode::Detailed;
        $report = $this->calculator->evaluate($employee, $from, $to);
        $rows = $this->buildRows($employee, $report, $mode);
        $content = $this->toCsv($rows, $mode);

        return [
            'content' => $content,
            'filename' => sprintf('seco_%s_%s_%s.csv', $employee->id, $from, $to),
            'mime' => 'text/csv; charset=UTF-8',
        ];
    }

    /**
     * Return all break-obligation and recording-mode compliance violations for the period.
     *
     * @return array<int, array{type: string, date: string, detail: array<string, mixed>}>
     */
    public function complianceAlerts(User $employee, string $from, string $to): array
    {
        $profile = TimeRecordingProfile::query()->where('user_id', $employee->id)->first();
        $mode = $profile->mode ?? TimeRecordingMode::Detailed;
        $report = $this->calculator->evaluate($employee, $from, $to);

        $alerts = [];

        if ($mode === TimeRecordingMode::Waived) {
            $alerts = array_merge($alerts, $this->waiverModeAlerts($profile));
        } else {
            foreach ($report->days as $day) {
                $breakViolation = $this->breakViolation($day);

                if ($breakViolation !== null) {
                    $alerts[] = $breakViolation;
                }
            }
        }

        return $alerts;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(User $employee, WorkingTimeReport $report, TimeRecordingMode $mode): array
    {
        $rows = [];

        if ($mode === TimeRecordingMode::Waived) {
            // Art. 73a: only exceptions (night, Sunday) need recording; return an empty set for a waived employee.
            return [];
        }

        foreach ($report->days as $day) {
            if ($day->workedMinutes === 0 && $day->creditedMinutes === 0) {
                continue;
            }

            [$supplemental, $overtime] = $this->splitSupplementalAndOvertime($day);

            $rows[] = [
                'employee_id' => $employee->id,
                'date' => $day->date->format('Y-m-d'),
                'mode' => $mode->value,
                'start' => $day->firstIn?->setTimezone('Europe/Zurich')->format('H:i'),
                'end' => $day->lastOut?->setTimezone('Europe/Zurich')->format('H:i'),
                'worked_hours' => $this->minutesToHours($day->workedMinutes),
                'break_hours' => $this->minutesToHours($day->breakMinutes),
                'night_hours' => $this->minutesToHours($day->nightMinutes),
                'sunday_work' => $day->sundayWork ? '1' : '0',
                'supplemental_hours' => $this->minutesToHours($supplemental),
                'overtime_hours' => $this->minutesToHours($overtime),
                'break_compliant' => $this->breakViolation($day) === null ? '1' : '0',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function toCsv(array $rows, TimeRecordingMode $mode): string
    {
        if ($mode === TimeRecordingMode::Waived) {
            return "# Art. 73a OLL 1: recording waived\r\n";
        }

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
     * Split the daily positive balance into Mehrstunden (supplemental, up to cap) and
     * Überstunden (statutory overtime beyond the cap).
     *
     * @return array{int, int}
     */
    private function splitSupplementalAndOvertime(WorkingDayResult $day): array
    {
        $balance = max(0, $day->balanceMinutes());

        $supplemental = min($balance, self::SUPPLEMENTAL_WEEKLY_MINUTES);
        $overtime = max(0, $balance - self::SUPPLEMENTAL_WEEKLY_MINUTES);

        return [$supplemental, $overtime];
    }

    /**
     * Check whether the break recorded satisfies LL Art. 15 thresholds.
     *
     * @return array{type: string, date: string, detail: array<string, mixed>}|null
     */
    private function breakViolation(WorkingDayResult $day): ?array
    {
        $worked = $day->workedMinutes;

        foreach (self::BREAK_THRESHOLDS as ['threshold_minutes' => $threshold, 'required_minutes' => $required]) {
            if ($worked > $threshold && $day->breakMinutes < $required) {
                return [
                    'type' => 'ch_break_violation',
                    'date' => $day->date->format('Y-m-d'),
                    'detail' => [
                        'worked_minutes' => $worked,
                        'break_minutes' => $day->breakMinutes,
                        'required_break_minutes' => $required,
                        'article' => 'LL Art. 15 / OLL 1 Art. 18',
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * Art. 73a OLL 1: waiver requires the profile to record the agreement date and confirm the
     * salary threshold (must be confirmed by HR, not auto-approved). Return alerts for missing data.
     *
     * @return array<int, array{type: string, date: string, detail: array<string, mixed>}>
     */
    private function waiverModeAlerts(?TimeRecordingProfile $profile): array
    {
        if ($profile === null) {
            return [[
                'type' => 'ch_waiver_no_profile',
                'date' => now()->format('Y-m-d'),
                'detail' => ['article' => 'Art. 73a OLL 1', 'issue' => 'No recording profile found for waived employee'],
            ]];
        }

        $alerts = [];

        if ($profile->agreement_date === null) {
            $alerts[] = [
                'type' => 'ch_waiver_missing_agreement_date',
                'date' => now()->format('Y-m-d'),
                'detail' => ['article' => 'Art. 73a OLL 1', 'issue' => 'Waiver agreement date not recorded'],
            ];
        }

        if ($profile->annual_gross_salary === null) {
            $alerts[] = [
                'type' => 'ch_waiver_salary_not_set',
                'date' => now()->format('Y-m-d'),
                'detail' => ['article' => 'Art. 73a OLL 1', 'issue' => 'Annual gross salary not set; salary threshold cannot be verified'],
            ];
        }

        return $alerts;
    }

    private function minutesToHours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }
}
