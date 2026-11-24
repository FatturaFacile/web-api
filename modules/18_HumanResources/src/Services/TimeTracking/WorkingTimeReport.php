<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * The working time evaluation of an employee for a period, as required for the labour inspectorate.
 */
final class WorkingTimeReport
{
    /** @var array<int, WorkingDayResult> */
    public array $days = [];

    /** @var array<int, WorkingWeekResult> */
    public array $weeks = [];

    /** @var array<int, string> The period issues (yearly overtime cap, recording mode). */
    public array $issues = [];

    public function __construct(
        public string $employeeId,
        public string $employeeName,
        public TimeRecordingMode $recordingMode,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $statutoryOvertimeBeforeMinutes,
        public int $statutoryOvertimeCapMinutes,
    ) {}

    public function workedMinutes(): int
    {
        return array_sum(array_map(static fn (WorkingDayResult $day): int => $day->workedMinutes, $this->days));
    }

    public function targetMinutes(): int
    {
        return array_sum(array_map(static fn (WorkingDayResult $day): int => $day->targetMinutes, $this->days));
    }

    public function creditedMinutes(): int
    {
        return array_sum(array_map(static fn (WorkingDayResult $day): int => $day->creditedMinutes, $this->days));
    }

    /**
     * The time balance of the period (overtime hours, "ore supplementari", if positive).
     */
    public function balanceMinutes(): int
    {
        return array_sum(array_map(static fn (WorkingDayResult $day): int => $day->balanceMinutes(), $this->days));
    }

    /**
     * The statutory overtime of the period.
     */
    public function statutoryOvertimeMinutes(): int
    {
        return array_sum(array_map(static fn (WorkingWeekResult $week): int => $week->statutoryOvertimeMinutes, $this->weeks));
    }

    /**
     * The statutory overtime of the year up to the end of the period.
     */
    public function statutoryOvertimeYearMinutes(): int
    {
        return $this->statutoryOvertimeBeforeMinutes + $this->statutoryOvertimeMinutes();
    }

    public function daysWithIssues(): int
    {
        return count(array_filter($this->days, static fn (WorkingDayResult $day): bool => $day->issues !== []));
    }

    /**
     * Format minutes as hours and minutes (e.g. "8:30", "-1:15").
     */
    public static function formatMinutes(int $minutes): string
    {
        $absolute = abs($minutes);

        return sprintf('%s%d:%02d', $minutes < 0 ? '-' : '', intdiv($absolute, 60), $absolute % 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'recording_mode' => $this->recordingMode->value,
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'days' => array_map(static fn (WorkingDayResult $day): array => $day->toArray(), $this->days),
            'weeks' => array_map(static fn (WorkingWeekResult $week): array => $week->toArray(), $this->weeks),
            'worked_minutes' => $this->workedMinutes(),
            'target_minutes' => $this->targetMinutes(),
            'credited_minutes' => $this->creditedMinutes(),
            'balance_minutes' => $this->balanceMinutes(),
            'balance' => self::formatMinutes($this->balanceMinutes()),
            'statutory_overtime_minutes' => $this->statutoryOvertimeMinutes(),
            'statutory_overtime_before_minutes' => $this->statutoryOvertimeBeforeMinutes,
            'statutory_overtime_year_minutes' => $this->statutoryOvertimeYearMinutes(),
            'statutory_overtime_cap_minutes' => $this->statutoryOvertimeCapMinutes,
            'days_with_issues' => $this->daysWithIssues(),
            'issues' => $this->issues,
        ];
    }
}
