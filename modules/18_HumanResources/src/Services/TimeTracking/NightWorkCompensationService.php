<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\TimeNightCompensationBalance;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\WorkingTimeCalculator;

/**
 * Swiss Labour Act ArG Art. 17b & OLL 1 Art. 31 Night Work Compensation Bank.
 *
 * - Employees performing regular or recurring night work (>= 25 night shifts per calendar year, or contractually
 *   classified as regular night workers) receive a mandatory 10% compensatory rest time credit (Zeitzuschlag)
 *   credited to their Night Work Compensation Bank (`time_night_compensation_balances`), which cannot be replaced
 *   by a cash payout during active employment.
 * - Employees performing occasional night work (< 25 night shifts per calendar year) receive a 25% cash wage supplement.
 */
final class NightWorkCompensationService
{
    public function __construct(private readonly WorkingTimeCalculator $calculator) {}

    /**
     * Evaluate and persist the YTD night work compensation balance of an employee for a calendar year.
     */
    public function evaluateYearToDate(
        User $employee,
        int $year,
        ?string $upToDate = null,
        bool $forceRegularNightWorker = false,
    ): TimeNightCompensationBalance {
        $from = sprintf('%04d-01-01', $year);
        $to = $upToDate ?? sprintf('%04d-12-31', $year);
        $report = $this->calculator->evaluate($employee, $from, $to);

        $ytdNightShifts = 0;
        $totalNightMinutes = 0;

        foreach ($report->days as $day) {
            if ($day->nightMinutes > 0) {
                $ytdNightShifts++;
                $totalNightMinutes += $day->nightMinutes;
            }
        }

        $profileFlag = false;
        if (Schema::hasTable('time_recording_profiles') && Schema::hasColumn('time_recording_profiles', 'is_regular_night_worker')) {
            $profileFlag = (bool) TimeRecordingProfile::query()->where('user_id', $employee->id)->value('is_regular_night_worker');
        }

        $hrEmployeeId = Employee::query()->where('user_id', $employee->id)->value('id');
        $existing = Schema::hasTable('time_night_compensation_balances')
            ? TimeNightCompensationBalance::query()->where('user_id', $employee->id)->where('year', $year)->first()
            : null;

        $isRegular = $forceRegularNightWorker
            || $profileFlag
            || (bool) ($existing?->is_regular_night_worker ?? false)
            || $ytdNightShifts >= SwissWorkingTimeRules::REGULAR_NIGHT_SHIFTS_THRESHOLD;

        $accruedRestMinutes = $isRegular
            ? SwissWorkingTimeRules::roundedDivision($totalNightMinutes * SwissWorkingTimeRules::NIGHT_TIME_COMPENSATION_PERCENT, 100)
            : 0;

        $cashSupplementMinutes = $isRegular
            ? 0
            : SwissWorkingTimeRules::roundedDivision($totalNightMinutes * SwissWorkingTimeRules::NIGHT_CASH_SUPPLEMENT_PERCENT, 100);

        $takenRestMinutes = (int) ($existing?->taken_rest_minutes ?? 0);
        $remainingRestMinutes = $accruedRestMinutes - $takenRestMinutes;
        $mode = $isRegular
            ? TimeNightCompensationBalance::ModeTime10Percent
            : TimeNightCompensationBalance::ModeCash25Percent;

        $attributes = [
            'user_id' => $employee->id,
            'employee_id' => $hrEmployeeId,
            'year' => $year,
            'ytd_night_shifts' => $ytdNightShifts,
            'is_regular_night_worker' => $isRegular,
            'total_night_minutes' => $totalNightMinutes,
            'accrued_rest_minutes' => $accruedRestMinutes,
            'taken_rest_minutes' => $takenRestMinutes,
            'remaining_rest_minutes' => $remainingRestMinutes,
            'cash_supplement_minutes' => $cashSupplementMinutes,
            'compensation_mode' => $mode,
        ];

        if (! Schema::hasTable('time_night_compensation_balances')) {
            return new TimeNightCompensationBalance($attributes);
        }

        $balance = $existing ?? new TimeNightCompensationBalance(['user_id' => $employee->id, 'year' => $year]);
        $balance->fill($attributes)->save();

        return $balance;
    }

    /**
     * Evaluate night work compensation for a specific period (e.g. a payroll month) in the context of the YTD night shifts.
     *
     * @return array{
     *     year: int,
     *     ytd_night_shifts: int,
     *     is_regular_night_worker: bool,
     *     compensation_mode: string,
     *     period_night_minutes: int,
     *     time_compensation_minutes: int,
     *     cash_supplement_minutes: int,
     *     ytd_accrued_rest_minutes: int,
     *     ytd_taken_rest_minutes: int,
     *     ytd_remaining_rest_minutes: int
     * }
     */
    public function evaluatePeriodForPayroll(User $employee, string $from, string $to, int $periodNightMinutes): array
    {
        $year = (int) substr($to, 0, 4);
        $balance = $this->evaluateYearToDate($employee, $year, $to);

        $timeCompensationMinutes = $balance->is_regular_night_worker
            ? SwissWorkingTimeRules::roundedDivision($periodNightMinutes * SwissWorkingTimeRules::NIGHT_TIME_COMPENSATION_PERCENT, 100)
            : 0;

        $cashSupplementMinutes = $balance->is_regular_night_worker
            ? 0
            : SwissWorkingTimeRules::roundedDivision($periodNightMinutes * SwissWorkingTimeRules::NIGHT_CASH_SUPPLEMENT_PERCENT, 100);

        return [
            'year' => $year,
            'ytd_night_shifts' => $balance->ytd_night_shifts,
            'is_regular_night_worker' => $balance->is_regular_night_worker,
            'compensation_mode' => $balance->compensation_mode,
            'period_night_minutes' => $periodNightMinutes,
            'time_compensation_minutes' => $timeCompensationMinutes,
            'cash_supplement_minutes' => $cashSupplementMinutes,
            'ytd_accrued_rest_minutes' => $balance->accrued_rest_minutes,
            'ytd_taken_rest_minutes' => $balance->taken_rest_minutes,
            'ytd_remaining_rest_minutes' => $balance->remaining_rest_minutes,
        ];
    }

    /**
     * Deduct compensatory rest time taken by a regular night worker from their ArG Art. 17b time bank.
     */
    public function recordRestTaken(User $employee, int $year, int $minutesTaken): TimeNightCompensationBalance
    {
        $balance = $this->evaluateYearToDate($employee, $year);
        $taken = max(0, $balance->taken_rest_minutes + max(0, $minutesTaken));
        $balance->fill([
            'taken_rest_minutes' => $taken,
            'remaining_rest_minutes' => $balance->accrued_rest_minutes - $taken,
        ])->save();

        return $balance;
    }
}
