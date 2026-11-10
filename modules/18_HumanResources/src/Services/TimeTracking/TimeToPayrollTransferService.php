<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollInput;

/**
 * Automatic Time-to-Payroll Transfer Service.
 *
 * Reads the evaluated working time and payroll view of each employee for a given period (year, month)
 * and automatically generates/upserts `Modules\Payroll\Models\PayrollInput` (`payroll_inputs` rows) for:
 * - Hourly worked hours (`HOURLY` / `1001`) for hourly-paid employees
 * - Overtime hours (`OVERTIME_125` / `1010`)
 * - Night work cash supplement (`NIGHT_125` / `1020`, when `< 25` nights/year per ArG Art. 17b al. 1)
 * - Sunday / Public holiday supplement (`SUNDAY_150` / `1030`)
 *
 * When an employee reaches `>= 25` night shifts in the calendar year (ArG Art. 17b al. 2), their 10%
 * mandatory compensatory rest time is credited to `TimeNightCompensationBalance` instead of cash supplement `1020`.
 */
final class TimeToPayrollTransferService
{
    public const string WAGE_CODE_HOURLY = '1001';

    public const string WAGE_CODE_OVERTIME_125 = '1010';

    public const string WAGE_CODE_NIGHT_125 = '1020';

    public const string WAGE_CODE_SUNDAY_150 = '1030';

    private readonly NightWorkCompensationService $nightCompensation;

    private readonly OvertimeCalculator $overtimeCalculator;

    public function __construct(
        private readonly PayrollTimeViewService $payrollTimeView,
        private readonly WorkingTimeCalculator $workingTimeCalculator,
        ?NightWorkCompensationService $nightCompensation = null,
        ?OvertimeCalculator $overtimeCalculator = null,
    ) {
        $this->nightCompensation = $nightCompensation ?? new NightWorkCompensationService($this->workingTimeCalculator);
        $this->overtimeCalculator = $overtimeCalculator ?? new OvertimeCalculator($this->workingTimeCalculator);
    }

    /**
     * Transfer attendance data for all employees of the current tenant for `(year, month)` into `payroll_inputs`.
     *
     * @return array{
     *     year: int,
     *     month: int,
     *     employees_processed: int,
     *     inputs_upserted: int,
     *     night_time_bank_credits: int,
     *     items: list<array<string, mixed>>
     * }
     */
    public function transfer(int $year, int $month, ?User $actor = null): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = CarbonImmutable::parse($from)->endOfMonth()->format('Y-m-d');

        return DB::transaction(function () use ($year, $month, $from, $to): array {
            $employees = Employee::query()->with('activeContract')->whereNotNull('user_id')->get();
            $employeesProcessed = 0;
            $inputsUpserted = 0;
            $nightTimeBankCredits = 0;
            $items = [];

            foreach ($employees as $employee) {
                $user = User::query()->find($employee->user_id);

                if ($user === null) {
                    continue;
                }

                $view = $this->payrollTimeView->view($user, $from, $to);
                $report = $this->workingTimeCalculator->evaluate($user, $from, $to);
                $overtime = $this->overtimeCalculator->calculate($user, $from, $to);
                $payrollProfile = PayrollEmployeeProfile::query()->where('employee_id', $employee->id)->first();

                $hourlyRate = $this->resolveHourlyRate($employee, $payrollProfile);
                $isHourlyPaid = $this->isHourlyPaid($employee, $payrollProfile);

                $workedMinutes = (int) ($view['totals']['payroll_minutes'] ?? 0);
                $periodNightMinutes = (int) array_sum(array_column($view['days'], 'night_minutes'));
                $nightEvaluation = $this->nightCompensation->evaluatePeriodForPayroll($user, $from, $to, $periodNightMinutes);

                if ($nightEvaluation['is_regular_night_worker'] && $nightEvaluation['time_compensation_minutes'] > 0) {
                    $nightTimeBankCredits++;
                }

                $employeeInputs = [];

                // 1. Hourly worked hours (HOURLY / 1001) for hourly-paid employees
                if ($isHourlyPaid && $workedMinutes > 0) {
                    $hours = round($workedMinutes / 60, 4);
                    $amount = round($hours * $hourlyRate, 2);
                    $input = $this->upsertInput(
                        employee: $employee,
                        year: $year,
                        month: $month,
                        wageTypeCode: self::WAGE_CODE_HOURLY,
                        quantity: $hours,
                        rate: $hourlyRate,
                        amount: $amount,
                        note: 'HOURLY: Worked hours transferred from time tracking',
                    );
                    $inputsUpserted++;
                    $employeeInputs[] = $input;
                }

                // 2. Overtime hours (OVERTIME_125 / 1010)
                $overtimeMinutes = $this->resolveOvertimeMinutes($overtime, $report);
                if ($overtimeMinutes > 0) {
                    $otHours = round($overtimeMinutes / 60, 4);
                    $otRate = round($hourlyRate * 1.25, 4);
                    $otAmount = round($otHours * $otRate, 2);
                    $input = $this->upsertInput(
                        employee: $employee,
                        year: $year,
                        month: $month,
                        wageTypeCode: self::WAGE_CODE_OVERTIME_125,
                        quantity: $otHours,
                        rate: $otRate,
                        amount: $otAmount,
                        note: 'OVERTIME_125: Overtime hours (125%) transferred from time tracking',
                    );
                    $inputsUpserted++;
                    $employeeInputs[] = $input;
                }

                // 3. Night work cash supplement (NIGHT_125 / 1020, only when < 25 nights/year per ArG Art. 17b)
                if ($periodNightMinutes > 0 && ! $nightEvaluation['is_regular_night_worker']) {
                    $nightHours = round($periodNightMinutes / 60, 4);
                    $nightSupplementRate = round($hourlyRate * 0.25, 4);
                    $nightAmount = round($nightHours * $nightSupplementRate, 2);
                    $input = $this->upsertInput(
                        employee: $employee,
                        year: $year,
                        month: $month,
                        wageTypeCode: self::WAGE_CODE_NIGHT_125,
                        quantity: $nightHours,
                        rate: $nightSupplementRate,
                        amount: $nightAmount,
                        note: 'NIGHT_125: ArG Art. 17b al. 1 occasional night work 25% cash supplement',
                    );
                    $inputsUpserted++;
                    $employeeInputs[] = $input;
                } elseif ($nightEvaluation['is_regular_night_worker']) {
                    // Regular night workers (>= 25 shifts/year) receive 10% compensatory rest time instead of cash
                    PayrollInput::query()
                        ->where('employee_id', $employee->id)
                        ->where('year', $year)
                        ->where('month', $month)
                        ->where('wage_type_code', self::WAGE_CODE_NIGHT_125)
                        ->delete();
                }

                // 4. Sunday / Public holiday supplement (SUNDAY_150 / 1030)
                $sundayHolidayMinutes = $this->resolveSundayAndHolidayMinutes($report, $view['days']);
                if ($sundayHolidayMinutes > 0) {
                    $sunHours = round($sundayHolidayMinutes / 60, 4);
                    $sunSupplementRate = round($hourlyRate * 0.50, 4);
                    $sunAmount = round($sunHours * $sunSupplementRate, 2);
                    $input = $this->upsertInput(
                        employee: $employee,
                        year: $year,
                        month: $month,
                        wageTypeCode: self::WAGE_CODE_SUNDAY_150,
                        quantity: $sunHours,
                        rate: $sunSupplementRate,
                        amount: $sunAmount,
                        note: 'SUNDAY_150: Sunday / Public holiday 50% supplement',
                    );
                    $inputsUpserted++;
                    $employeeInputs[] = $input;
                }

                if ($workedMinutes > 0 || $periodNightMinutes > 0 || $overtimeMinutes > 0 || $sundayHolidayMinutes > 0) {
                    $employeesProcessed++;
                    $items[] = [
                        'employee_id' => $employee->id,
                        'user_id' => $employee->user_id,
                        'worked_minutes' => $workedMinutes,
                        'overtime_minutes' => $overtimeMinutes,
                        'night_minutes' => $periodNightMinutes,
                        'sunday_holiday_minutes' => $sundayHolidayMinutes,
                        'night_compensation' => $nightEvaluation,
                        'payroll_inputs' => array_map(static fn (PayrollInput $in): array => [
                            'id' => $in->id,
                            'wage_type_code' => $in->wage_type_code,
                            'quantity' => (float) $in->quantity,
                            'rate' => (float) $in->rate,
                            'amount' => (float) $in->amount,
                            'note' => $in->note,
                        ], $employeeInputs),
                    ];
                }
            }

            return [
                'year' => $year,
                'month' => $month,
                'employees_processed' => $employeesProcessed,
                'inputs_upserted' => $inputsUpserted,
                'night_time_bank_credits' => $nightTimeBankCredits,
                'items' => $items,
            ];
        });
    }

    private function upsertInput(
        Employee $employee,
        int $year,
        int $month,
        string $wageTypeCode,
        float $quantity,
        float $rate,
        float $amount,
        string $note,
    ): PayrollInput {
        $existing = PayrollInput::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('wage_type_code', $wageTypeCode)
            ->first();

        if ($existing !== null) {
            $existing->fill([
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => $amount,
                'note' => $note,
            ])->save();

            return $existing;
        }

        return PayrollInput::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'year' => $year,
            'month' => $month,
            'wage_type_code' => $wageTypeCode,
            'quantity' => $quantity,
            'rate' => $rate,
            'amount' => $amount,
            'note' => $note,
        ]);
    }

    private function isHourlyPaid(Employee $employee, ?PayrollEmployeeProfile $payrollProfile): bool
    {
        if ($payrollProfile !== null && strtolower((string) $payrollProfile->pay_type) === 'hourly') {
            return true;
        }

        $contract = $employee->activeContract;
        $contractType = strtolower((string) ($contract !== null ? $contract->contract_type : ''));
        if (strtolower((string) $employee->employment_type) === 'hourly' || $contractType === 'hourly') {
            return true;
        }

        $contractHourly = (float) ($contract !== null ? $contract->hourly_rate : 0);
        $baseAnnual = (float) ($employee->base_annual_salary ?? 0);

        return $contractHourly > 0 && $baseAnnual <= 0;
    }

    private function resolveHourlyRate(Employee $employee, ?PayrollEmployeeProfile $payrollProfile): float
    {
        if ($payrollProfile !== null && (float) $payrollProfile->hourly_wage > 0) {
            return round((float) $payrollProfile->hourly_wage, 4);
        }

        $contract = $employee->activeContract;
        if ($contract !== null && (float) $contract->hourly_rate > 0) {
            return round((float) $contract->hourly_rate, 4);
        }

        if ((float) $employee->hourly_cost_rate > 0) {
            return round((float) $employee->hourly_cost_rate, 4);
        }

        $baseAnnual = (float) ($employee->base_annual_salary ?? ($contract !== null ? ($contract->base_annual_salary ?? 0) : 0));
        $weeklyHours = (float) ($payrollProfile !== null && $payrollProfile->weekly_hours > 0 ? $payrollProfile->weekly_hours : ($contract !== null && $contract->weekly_hours > 0 ? $contract->weekly_hours : 42.0));

        if ($baseAnnual > 0 && $weeklyHours > 0) {
            return round($baseAnnual / ($weeklyHours * 52), 4);
        }

        return 35.0;
    }

    /**
     * @param  array{totals: list<array<string, mixed>>}  $overtime
     */
    private function resolveOvertimeMinutes(array $overtime, WorkingTimeReport $report): int
    {
        if ($overtime['totals'] !== []) {
            return (int) array_sum(array_column($overtime['totals'], 'minutes'));
        }

        if ($report->statutoryOvertimeMinutes() > 0) {
            return $report->statutoryOvertimeMinutes();
        }

        $dailyOvertime = 0;
        foreach ($report->days as $day) {
            if ($day->targetMinutes > 0 && $day->workedMinutes > $day->targetMinutes) {
                $dailyOvertime += $day->workedMinutes - $day->targetMinutes;
            }
        }

        return $dailyOvertime;
    }

    /**
     * @param  list<array<string, mixed>>  $viewDays
     */
    private function resolveSundayAndHolidayMinutes(WorkingTimeReport $report, array $viewDays): int
    {
        $viewByDate = [];
        foreach ($viewDays as $viewDay) {
            $viewByDate[(string) $viewDay['date']] = $viewDay;
        }

        $minutes = 0;
        foreach ($report->days as $day) {
            $date = $day->date->format('Y-m-d');
            $vDay = $viewByDate[$date] ?? null;
            $payrollMins = (int) ($vDay['payroll_minutes'] ?? $day->workedMinutes);
            $isHolidayWork = ((int) ($vDay['holiday_allowance_minutes'] ?? 0) > 0) || ($day->absence !== null && $payrollMins > 0);

            if ($payrollMins > 0 && ($day->sundayWork || $isHolidayWork)) {
                $minutes += $payrollMins;
            }
        }

        return $minutes;
    }
}
