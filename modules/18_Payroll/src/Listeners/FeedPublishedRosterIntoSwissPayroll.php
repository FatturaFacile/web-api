<?php

declare(strict_types=1);

namespace Modules\Payroll\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollWageType;
use Modules\ShiftPlanning\Domain\Events\RosterPublishedEvent;
use Modules\ShiftPlanning\Domain\Events\ShiftAssignmentFinalizedEvent;

/**
 * Swiss payroll (Mod 076) receives the surcharge minutes of the published Swiss rosters of Mod 073 (night, Sunday, holiday,
 * on-call) as variable inputs of the month. The inputs of a month are rebuilt from the published / locked rosters each
 * time (delete the rows this feed wrote, insert the current ones), so republishing, claiming an open shift or approving a
 * swap never duplicates a line. Manually entered inputs are never touched: this feed owns only its four wage types.
 * Months with a final payroll run stay frozen. Italian rosters are read by Mod 077 directly.
 */
final class FeedPublishedRosterIntoSwissPayroll
{
    public const string NotePrefix = 'ROSTER: ';

    /**
     * Surcharge key => [wage type, default percent of the hourly wage (override: payroll.roster_surcharge_percent.<key>)].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array Map = [
        'night_minutes' => [SwissWageTypeCatalog::RosterNightSurcharge, '25'],
        'sunday_minutes' => [SwissWageTypeCatalog::RosterSundaySurcharge, '50'],
        'holiday_minutes' => [SwissWageTypeCatalog::RosterHolidaySurcharge, '50'],
        'on_call_minutes' => [SwissWageTypeCatalog::RosterOnCallAllowance, '10'],
    ];

    public function handleRosterPublished(RosterPublishedEvent $event): void
    {
        if ($event->jurisdiction === 'CH') {
            $this->sync($event->tenantId, $event->startDate, $event->endDate);
        }
    }

    public function handleAssignmentFinalized(ShiftAssignmentFinalizedEvent $event): void
    {
        $row = DB::table('roster_shift_assignments as a')->join('roster_schedule_periods as p', 'p.id', '=', 'a.roster_schedule_period_id')
            ->where('a.tenant_id', $event->tenantId)->where('a.id', $event->assignmentId)->first(['a.shift_date', 'p.jurisdiction']);

        if ($row !== null && $row->jurisdiction === 'CH') {
            $date = CarbonImmutable::parse((string) $row->shift_date)->toDateString();
            $this->sync($event->tenantId, $date, $date);
        }
    }

    /**
     * Rebuilds the inputs of every month touched by the days between `$from` and `$to` (inclusive) for the tenant.
     *
     * @return int number of input rows written
     */
    public function sync(string $tenantId, string $from, string $to): int
    {
        $written = 0;

        for ($month = CarbonImmutable::parse($from)->startOfMonth(); $month <= CarbonImmutable::parse($to); $month = $month->addMonth()) {
            $written += $this->syncMonth($tenantId, $month->year, $month->month);
        }

        return $written;
    }

    private function syncMonth(string $tenantId, int $year, int $month): int
    {
        $closed = PayrollRun::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('year', $year)->where('month', $month)
            ->where('status', PayrollRun::StatusFinal)->exists();

        if ($closed) {
            return 0;
        }

        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $totals = $this->minutesByEmployee($tenantId, $start->toDateString(), $start->endOfMonth()->toDateString());

        return DB::transaction(function () use ($tenantId, $year, $month, $totals): int {
            DB::table('payroll_inputs')->where('tenant_id', $tenantId)->where('year', $year)->where('month', $month)
                ->whereIn('wage_type_code', array_column(self::Map, 0))->where('note', 'like', self::NotePrefix.'%')->delete();

            if ($totals === []) {
                return 0;
            }

            $this->ensureWageTypes($tenantId);
            $employees = Employee::withoutGlobalScopes()->with('activeContract')->where('tenant_id', $tenantId)
                ->whereIn('id', array_keys($totals))->get()->keyBy('id');
            $profiles = PayrollEmployeeProfile::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->whereIn('employee_id', array_keys($totals))->get()->keyBy('employee_id');
            $now = now();
            $rows = [];

            foreach ($totals as $employeeId => $minutes) {
                $employee = $employees->get($employeeId);

                if ($employee === null) {
                    continue;
                }

                $hourly = $this->hourlyRate($employee, $profiles->get($employeeId));

                foreach (self::Map as $key => [$code, $defaultPercent]) {
                    if (($minutes[$key] ?? 0) <= 0) {
                        continue;
                    }

                    $percent = (string) config('payroll.roster_surcharge_percent.'.$key, $defaultPercent);
                    $hours = Dec::div((string) $minutes[$key], '60');
                    $rate = Dec::div(Dec::mul($hourly, $percent), '100');
                    $rows[] = [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'employee_id' => $employeeId,
                        'year' => $year,
                        'month' => $month,
                        'wage_type_code' => $code,
                        'quantity' => $hours,
                        'rate' => $rate,
                        'amount' => Dec::mul($hours, $rate),
                        'note' => self::NotePrefix.'Mod 073 '.$key,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('payroll_inputs')->insert($rows);
            }

            return count($rows);
        });
    }

    /**
     * @return array<string, array<string, int>> employee id => surcharge key => minutes
     */
    private function minutesByEmployee(string $tenantId, string $from, string $to): array
    {
        $rows = DB::table('roster_shift_assignments as a')->join('roster_schedule_periods as p', 'p.id', '=', 'a.roster_schedule_period_id')
            ->where('a.tenant_id', $tenantId)->where('p.jurisdiction', 'CH')->whereNotNull('a.employee_id')
            ->whereNull('a.deleted_at')->whereNull('p.deleted_at')->whereIn('p.status', ['published', 'locked'])
            ->whereBetween('a.shift_date', [$from, $to])
            ->get(['a.employee_id', 'a.surcharge_breakdown']);

        $totals = [];

        foreach ($rows as $row) {
            $breakdown = is_string($row->surcharge_breakdown) ? json_decode($row->surcharge_breakdown, true) : null;

            if (! is_array($breakdown)) {
                continue;
            }

            foreach (array_keys(self::Map) as $key) {
                $totals[(string) $row->employee_id][$key] = ($totals[(string) $row->employee_id][$key] ?? 0) + (int) ($breakdown[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function hourlyRate(Employee $employee, ?PayrollEmployeeProfile $profile): string
    {
        $contract = $employee->activeContract;

        foreach ([$profile?->hourly_wage, $contract?->hourly_rate, $employee->hourly_cost_rate] as $candidate) {
            if ($candidate !== null && (float) $candidate > 0) {
                return (string) $candidate;
            }
        }

        $annual = (float) ($employee->base_annual_salary ?? ($contract !== null ? $contract->base_annual_salary : 0));
        $weekly = (float) ($profile?->weekly_hours > 0 ? $profile->weekly_hours : ($contract?->weekly_hours > 0 ? $contract->weekly_hours : 42));

        return $annual > 0 ? Dec::div((string) $annual, (string) ($weekly * 52)) : '0';
    }

    /**
     * Companies set up before this feed get its wage types when they first receive a roster.
     */
    private function ensureWageTypes(string $tenantId): void
    {
        $existing = PayrollWageType::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('code')->all();

        foreach (SwissWageTypeCatalog::rosterSurchargeTypes() as $type) {
            if (in_array($type->code, $existing, true)) {
                continue;
            }

            PayrollWageType::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'code' => $type->code,
                'name' => $type->name,
                'kind' => $type->kind,
                'subject_to_ahv' => $type->subjectToAhv,
                'subject_to_alv' => $type->subjectToAlv,
                'subject_to_uvg' => $type->subjectToUvg,
                'subject_to_ktg' => $type->subjectToKtg,
                'subject_to_bvg' => $type->subjectToBvg,
                'subject_to_withholding_tax' => $type->subjectToWithholdingTax,
                'is_irregular' => $type->isIrregular,
                'certificate_box' => $type->certificateBox,
                'sort_order' => $type->sortOrder,
                'is_system' => $type->isSystem,
            ]);
        }
    }
}
