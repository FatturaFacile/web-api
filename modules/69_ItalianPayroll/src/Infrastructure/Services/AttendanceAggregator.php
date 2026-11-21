<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;
use Modules\ItalianPayroll\Domain\Enums\CausalCode;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\Payroll\Calculation\Dec;

/**
 * Collects the month of every employee from the operational modules (clock entries, approved leaves, meal bookings,
 * reimbursable expenses, fringe benefits) into LUL lines, and reports the inconsistencies that must be fixed before the
 * month can be validated. It reads only: persisting the lines is the handler's job.
 *
 * Overtime: a day beyond 8 worked hours counts as overtime; the first 2 hours are STR25, the rest STR50.
 */
final class AttendanceAggregator
{
    public const int DailyMinutes = 480;

    public const int FirstTierMinutes = 120;

    public const int ImplausibleDayMinutes = 720;

    /**
     * @param  array<string, string>  $fringeBenefits  employee id => taxable fringe benefit of the month
     * @return array{lines: list<array<string, mixed>>, errors: list<array{employee_id: string, code: string, message: string}>, warnings: list<array{employee_id: string, code: string, message: string}>}
     */
    public function aggregate(ItalianPayrollPeriod $period, array $fringeBenefits = []): array
    {
        $start = CarbonImmutable::create($period->year, $period->month, 1)->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $lines = [];
        $errors = [];
        $warnings = [];

        $employees = Employee::query()
            ->when($period->legal_entity_id !== null, fn ($query) => $query->where('company_id', $period->legal_entity_id))
            ->orderBy('employee_number')
            ->get();

        foreach ($employees as $employee) {
            $employeeId = (string) $employee->id;
            $matricola = (string) $employee->employee_number;
            $userId = $employee->user_id !== null ? (string) $employee->user_id : null;

            if (! is_string($employee->tax_code) || trim($employee->tax_code) === '') {
                $errors[] = ['employee_id' => $employeeId, 'code' => 'missing_tax_code', 'message' => "Codice fiscale mancante per {$employee->full_name}."];
            }

            $leaves = $this->leaves((string) $period->tenant_id, $employeeId, $start, $end, $errors, $warnings);

            foreach ($leaves as $leave) {
                $lines[] = $this->line($employeeId, $matricola, $leave['causal'], $leave['quantity'], 0, '0', '0', $leave['protocol']);
            }

            if ($userId === null) {
                continue;
            }

            $daily = DB::table('time_daily_records')->where('tenant_id', $period->tenant_id)->where('user_id', $userId)->whereNull('deleted_at')
                ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->pluck('worked_minutes', 'work_date');

            [$ordinary, $tier25, $tier50] = $this->hours($daily->all(), $employeeId, $warnings);
            $leaveDays = $this->leaveDays($leaves);
            $this->flagMissingClockIns((string) $period->tenant_id, $daily->all(), $leaveDays, $start, $end, $employeeId, $warnings);

            $vouchers = (int) DB::table('time_meal_bookings')->where('tenant_id', $period->tenant_id)->where('user_id', $userId)->whereNull('deleted_at')
                ->whereBetween('meal_date', [$start->toDateString(), $end->toDateString()])->distinct()->count('meal_date');
            $reimbursement = (string) (DB::table('expenses')->where('tenant_id', $period->tenant_id)->where('user_id', $userId)->whereNull('deleted_at')->where('status', 'approved')
                ->where('is_reimbursable', true)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->sum('amount_gross'));
            $fringe = $fringeBenefits[$employeeId] ?? '0';

            $primary = [[CausalCode::Ordinario, $ordinary], [CausalCode::Straordinario25, $tier25], [CausalCode::Straordinario50, $tier50]];
            $first = true;

            foreach ($primary as [$causal, $hours]) {
                if (Dec::isPositive($hours)) {
                    // Vouchers, reimbursement and fringe benefit ride on the first line of the employee, never repeated.
                    $lines[] = $this->line($employeeId, $matricola, $causal, $hours, $first ? $vouchers : 0, $first ? $reimbursement : '0', $first ? $fringe : '0', null);
                    $first = false;
                }
            }

            if ($first && ($vouchers > 0 || Dec::isPositive($reimbursement) || Dec::isPositive($fringe))) {
                $lines[] = $this->line($employeeId, $matricola, CausalCode::Ordinario, '0', $vouchers, $reimbursement, $fringe, null);
            }
        }

        return ['lines' => $lines, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  list<array{employee_id: string, code: string, message: string}>  $errors
     * @param  list<array{employee_id: string, code: string, message: string}>  $warnings
     * @return list<array{causal: CausalCode, quantity: string, protocol: string|null, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private function leaves(string $tenantId, string $employeeId, CarbonImmutable $start, CarbonImmutable $end, array &$errors, array &$warnings): array
    {
        $requests = DB::table('hr_leave_requests as r')->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.tenant_id', $tenantId)->where('r.employee_id', $employeeId)->whereNull('r.deleted_at')
            ->where('r.start_date', '<=', $end->toDateString())->where('r.end_date', '>=', $start->toDateString())
            ->whereIn('r.status', ['approved', 'pending_approval'])
            ->get(['r.id', 'r.start_date', 'r.end_date', 'r.total_hours', 'r.total_days', 'r.status', 'r.medical_certificate_ref', 't.category', 't.code'])->all();

        $result = [];
        $approved = [];

        foreach ($requests as $request) {
            if ($request->status === 'pending_approval') {
                $warnings[] = ['employee_id' => $employeeId, 'code' => 'unapproved_leave', 'message' => "Richiesta di assenza {$request->id} non ancora approvata."];

                continue;
            }

            $causal = CausalCode::fromLeaveCategory((string) $request->category, (string) $request->code);

            if ($causal === null) {
                continue;
            }

            $from = CarbonImmutable::parse($request->start_date)->max($start);
            $to = CarbonImmutable::parse($request->end_date)->min($end);

            foreach ($approved as $other) {
                if ($from <= $other['to'] && $to >= $other['from']) {
                    $errors[] = ['employee_id' => $employeeId, 'code' => 'overlapping_leave', 'message' => "Assenze sovrapposte dal {$from->toDateString()} al {$to->toDateString()}."];
                }
            }

            $protocol = is_string($request->medical_certificate_ref) && $request->medical_certificate_ref !== '' ? $request->medical_certificate_ref : null;

            if ($causal->requiresInpsProtocol() && $protocol === null) {
                $errors[] = ['employee_id' => $employeeId, 'code' => 'missing_inps_protocol', 'message' => "Malattia dal {$from->toDateString()} senza numero di protocollo INPS."];
            }

            $days = $this->weekdays($from, $to);
            $hoursPerDay = (float) $request->total_days > 0 ? (float) $request->total_hours / (float) $request->total_days : 8.0;
            $quantity = $causal->isHours() ? number_format($days * $hoursPerDay, 2, '.', '') : number_format($days, 2, '.', '');
            $approved[] = ['from' => $from, 'to' => $to];
            $result[] = ['causal' => $causal, 'quantity' => $quantity, 'protocol' => $protocol, 'from' => $from, 'to' => $to];
        }

        return $result;
    }

    /**
     * @param  array<string, int|string>  $daily  work date => worked minutes
     * @param  list<array{employee_id: string, code: string, message: string}>  $warnings
     * @return array{0: string, 1: string, 2: string} ordinary, STR25 and STR50 hours
     */
    private function hours(array $daily, string $employeeId, array &$warnings): array
    {
        $ordinary = 0;
        $tier25 = 0;
        $tier50 = 0;

        foreach ($daily as $date => $minutes) {
            $minutes = (int) $minutes;
            $overtime = max(0, $minutes - self::DailyMinutes);
            $ordinary += min($minutes, self::DailyMinutes);
            $tier25 += min($overtime, self::FirstTierMinutes);
            $tier50 += max(0, $overtime - self::FirstTierMinutes);

            if ($minutes > self::ImplausibleDayMinutes) {
                $warnings[] = ['employee_id' => $employeeId, 'code' => 'excessive_day', 'message' => "Oltre 12 ore registrate il {$date}: verificare lo straordinario."];
            }
        }

        $toHours = static fn (int $minutes): string => number_format($minutes / 60, 2, '.', '');

        return [$toHours($ordinary), $toHours($tier25), $toHours($tier50)];
    }

    /**
     * @param  list<array{causal: CausalCode, quantity: string, protocol: string|null, from: CarbonImmutable, to: CarbonImmutable}>  $leaves
     * @return array<string, true>
     */
    private function leaveDays(array $leaves): array
    {
        $days = [];

        foreach ($leaves as $leave) {
            for ($day = $leave['from']; $day <= $leave['to']; $day = $day->addDay()) {
                $days[$day->toDateString()] = true;
            }
        }

        return $days;
    }

    /**
     * @param  array<string, int|string>  $daily
     * @param  array<string, true>  $leaveDays
     * @param  list<array{employee_id: string, code: string, message: string}>  $warnings
     */
    private function flagMissingClockIns(string $tenantId, array $daily, array $leaveDays, CarbonImmutable $start, CarbonImmutable $end, string $employeeId, array &$warnings): void
    {
        if ($daily === []) {
            return;
        }

        $holidays = DB::table('time_public_holidays')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])->pluck('holiday_date')->map(fn ($d): string => substr((string) $d, 0, 10))->all();

        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $key = $day->toDateString();

            if ($day->isWeekend() || isset($daily[$key]) || isset($leaveDays[$key]) || in_array($key, $holidays, true)) {
                continue;
            }

            $warnings[] = ['employee_id' => $employeeId, 'code' => 'missing_clock_in', 'message' => "Nessuna timbratura il {$key}."];
        }
    }

    private function weekdays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $count = 0;

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $count += $day->isWeekend() ? 0 : 1;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $employeeId, string $matricola, CausalCode $causal, string $quantity, int $vouchers, string $reimbursement, string $fringe, ?string $protocol): array
    {
        return [
            'employee_id' => $employeeId, 'matricola_inps' => $matricola, 'causal_code' => $causal->value, 'hours_or_days' => $quantity,
            'meal_vouchers_count' => $vouchers, 'reimbursement_amount' => Dec::round($reimbursement), 'fringe_benefit_amount' => Dec::round($fringe),
            'inps_protocol_number' => $protocol,
        ];
    }
}
