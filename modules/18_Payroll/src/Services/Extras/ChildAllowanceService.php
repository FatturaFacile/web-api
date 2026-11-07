<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Extras;

use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\ChildData;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\Swiss\SwissPayrollRules;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\SwissPayrollSettings;

/**
 * Links each child record to the allowance it gives in a month, with the rule the engine applies: the payslip shows the
 * total, this shows which child it comes from.
 */
final class ChildAllowanceService
{
    /**
     * @return list<array{child_id: string, employee_id: string, employee_name: string, kind: string|null, amount: string|null, canton: string, rate_valid_from: string|null}>
     */
    public function overview(int $year, int $month, ?string $employeeId): array
    {
        $periodStart = PayrollDates::monthStart($year, $month);
        $periodEnd = PayrollDates::monthEnd($year, $month);
        $children = PayrollEmployeeChild::query()->when($employeeId !== null, static fn ($query) => $query->where('employee_id', $employeeId))->get();
        $employees = Employee::query()->whereIn('id', $children->pluck('employee_id'))->pluck('full_name', 'id');
        $cantons = PayrollEmployeeProfile::query()->whereIn('employee_id', $children->pluck('employee_id'))->pluck('work_canton', 'employee_id');
        $defaultCanton = (string) SwissPayrollSettings::query()->value('default_work_canton');
        $rates = PayrollFamilyAllowanceRate::query()->whereDate('valid_from', '<=', $periodEnd->format('Y-m-d'))->orderByDesc('valid_from')->get();
        $overview = [];

        foreach ($children as $child) {
            $canton = strtoupper((string) ($cantons->get($child->employee_id) ?? $defaultCanton));
            $rate = $rates->first(static fn (PayrollFamilyAllowanceRate $candidate): bool => strcasecmp($candidate->canton, $canton) === 0);
            $kind = SwissPayrollRules::familyAllowanceKind(new ChildData(
                PayrollDates::from($child->date_of_birth),
                (bool) $child->in_education,
                $child->education_end_date === null ? null : PayrollDates::from($child->education_end_date),
                (bool) $child->allowance_eligible,
            ), $periodStart, $periodEnd);

            $overview[] = [
                'child_id' => (string) $child->id,
                'employee_id' => (string) $child->employee_id,
                'employee_name' => (string) $employees->get($child->employee_id),
                'kind' => $kind,
                'amount' => match ($kind) {
                    SwissPayrollRules::ChildAllowanceKind => $rate?->child_allowance === null ? null : (string) $rate->child_allowance,
                    SwissPayrollRules::EducationAllowanceKind => $rate?->education_allowance === null ? null : (string) $rate->education_allowance,
                    default => null,
                },
                'canton' => $canton,
                'rate_valid_from' => $rate?->valid_from?->format('Y-m-d'),
            ];
        }

        return $overview;
    }
}
