<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Models\TimeScheduleGroupMember;
use Modules\HumanResources\Models\TimeScheduleGroupRule;
use Modules\HumanResources\Services\Shifts\ShiftCategory;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\Payroll\Calculation\Dec;

/**
 * The working time as the payroll may read it: tolerance towards the published shift, rounding, and night, Sunday, holiday
 * and daily allowances of the schedule group in force on each day. It is computed on demand and stored nowhere. The
 * recorded minutes are returned untouched next to the derived ones, and no clock event is ever changed: the working time
 * evidence of art. 73 OLL 1 stays the real minutes. The percentages come from the rules of the group, that is from the
 * contract or the CCL: none is assumed here.
 */
final class PayrollTimeViewService
{
    public function __construct(private readonly WorkingTimeCalculator $calculator) {}

    /**
     * @return array{employee_id: string, from: string, to: string, days: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function view(User $employee, string $from, string $to): array
    {
        $report = $this->calculator->evaluate($employee, $from, $to);
        $intervals = $this->intervals($employee, $from, $to);
        $holidays = $this->holidayDates($employee, $from, $to);
        $shifts = $this->workingShifts($employee, $from, $to);
        $days = [];

        foreach ($report->days as $day) {
            $date = $day->date->format('Y-m-d');
            $rule = $this->ruleOn((string) $employee->id, $date);
            $today = array_values(array_filter($intervals, static fn (WorkInterval $interval): bool => $interval->start->format('Y-m-d') === $date));
            $days[] = $this->day($day, $rule, $today, $shifts->get($date), isset($holidays[$date]));
        }

        return [
            'employee_id' => (string) $employee->id,
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'totals' => [
                'recorded_minutes' => array_sum(array_column($days, 'recorded_minutes')),
                'payroll_minutes' => array_sum(array_column($days, 'payroll_minutes')),
                'night_allowance_minutes' => array_sum(array_column($days, 'night_allowance_minutes')),
                'sunday_allowance_minutes' => array_sum(array_column($days, 'sunday_allowance_minutes')),
                'holiday_allowance_minutes' => array_sum(array_column($days, 'holiday_allowance_minutes')),
                'daily_allowance_amount' => Dec::round(Dec::sum(array_column($days, 'daily_allowance_amount'))),
            ],
        ];
    }

    /**
     * @param  list<WorkInterval>  $intervals
     * @return array<string, mixed>
     */
    private function day(WorkingDayResult $day, ?TimeScheduleGroupRule $rule, array $intervals, ?Shift $shift, bool $isHoliday): array
    {
        $payrollMinutes = $this->payrollMinutes($day, $rule, $intervals, $shift);

        return [
            'date' => $day->date->format('Y-m-d'),
            'recorded_minutes' => $day->workedMinutes,
            'payroll_minutes' => $payrollMinutes,
            'adjustment_minutes' => $payrollMinutes - $day->workedMinutes,
            'night_minutes' => $day->nightMinutes,
            'night_allowance_minutes' => self::share($day->nightMinutes, $rule?->night_allowance_percent),
            'sunday_allowance_minutes' => $day->sundayWork ? self::share($payrollMinutes, $rule?->sunday_allowance_percent) : 0,
            'holiday_allowance_minutes' => $isHoliday ? self::share($payrollMinutes, $rule?->holiday_allowance_percent) : 0,
            'daily_allowance_amount' => $rule?->daily_allowance_amount !== null && $payrollMinutes > 0 && $payrollMinutes >= $rule->daily_allowance_minimum_minutes
                ? (string) $rule->daily_allowance_amount
                : '0',
            'rule_id' => $rule?->id,
        ];
    }

    /**
     * @param  list<WorkInterval>  $intervals
     */
    private function payrollMinutes(WorkingDayResult $day, ?TimeScheduleGroupRule $rule, array $intervals, ?Shift $shift): int
    {
        if ($rule === null || $intervals === []) {
            return $day->workedMinutes;
        }

        $mode = PunchRoundingMode::from((string) $rule->rounding_mode);
        $minutes = 0;
        $last = count($intervals) - 1;

        foreach ($intervals as $index => $interval) {
            $start = $index === 0 ? self::towardsPlan($interval->start, $shift?->starts_at, $rule) : $interval->start;
            $end = $index === $last ? self::towardsPlan($interval->end, $shift?->ends_at, $rule) : $interval->end;
            $minutes += max(0, SwissWorkingTimeRules::minutesBetween(
                $mode->round($start, $rule->rounding_minutes, true),
                $mode->round($end, $rule->rounding_minutes, false),
            ));
        }

        return $minutes;
    }

    /**
     * A punch within the tolerance around the planned time counts at the planned time.
     */
    private static function towardsPlan(CarbonImmutable $punch, ?CarbonImmutable $planned, TimeScheduleGroupRule $rule): CarbonImmutable
    {
        if ($planned === null) {
            return $punch;
        }

        $difference = SwissWorkingTimeRules::minutesBetween($planned, $punch);
        $isWithin = $difference < 0 ? -$difference <= $rule->tolerance_before_minutes : $difference <= $rule->tolerance_after_minutes;

        return $isWithin ? SwissWorkingTimeRules::toLocal($planned) : $punch;
    }

    private static function share(int $minutes, ?string $percent): int
    {
        if ($percent === null || $minutes === 0) {
            return 0;
        }

        return (int) Dec::round(Dec::div(Dec::mul((string) $minutes, $percent), '100'), 0);
    }

    private function ruleOn(string $userId, string $date): ?TimeScheduleGroupRule
    {
        $membership = TimeScheduleGroupMember::query()
            ->where('user_id', $userId)
            ->whereDate('valid_from', '<=', $date)
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('valid_from')
            ->first();

        return $membership === null ? null : TimeScheduleGroupRule::query()
            ->where('schedule_group_id', $membership->schedule_group_id)
            ->whereDate('valid_from', '<=', $date)
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * @return list<WorkInterval>
     */
    private function intervals(User $employee, string $from, string $to): array
    {
        $events = TimeClockEvent::query()
            ->where('user_id', $employee->id)
            ->betweenLocalDays(SwissWorkingTimeRules::localDate($from)->subDay()->format('Y-m-d'), SwissWorkingTimeRules::localDate($to)->addDay()->format('Y-m-d'))
            ->orderBy('occurred_at')
            ->get()
            ->map(static fn (TimeClockEvent $event): ClockEntry => new ClockEntry($event->occurred_at, $event->event_type))
            ->all();

        return SwissWorkingTimeEngine::buildIntervals($events, CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE))[0];
    }

    /**
     * @return Collection<string, Shift>
     */
    private function workingShifts(User $employee, string $from, string $to): Collection
    {
        return Shift::query()
            ->with('type')
            ->published()
            ->whereIn('employee_id', Employee::query()->where('user_id', $employee->id)->pluck('id'))
            ->betweenDays($from, $to)
            ->get()
            ->filter(static fn (Shift $shift): bool => $shift->type?->category === ShiftCategory::Working)
            ->keyBy(static fn (Shift $shift): string => $shift->work_date->format('Y-m-d'));
    }

    /**
     * @return array<string, true>
     */
    private function holidayDates(User $employee, string $from, string $to): array
    {
        $canton = TimeRecordingProfile::query()->where('user_id', $employee->id)->value('canton');
        $dates = [];

        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            foreach ($this->calculator->holidays($canton, $year) as $holiday) {
                $dates[$holiday->date] = true;
            }
        }

        return $dates;
    }
}
