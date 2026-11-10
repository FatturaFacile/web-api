<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Employee;

/**
 * The payroll data of an employee that hr_employees does not hold.
 *
 * @property string|null $swiss_permit_type
 * @property Carbon|null $swiss_permit_valid_until
 * @property string|null $swiss_permit_number
 * @property string|null $qst_rate_determining_percent
 * @property string|null $other_employment_fte_percent
 * @property bool|null $extrapolate_qst_to_full_time
 * @property bool|null $frontier_apply_80_percent_basis
 * @property bool|null $single_parent_concubinage
 */
final class PayrollEmployeeProfile extends PayrollRecord
{
    protected $table = 'payroll_employee_profiles';

    protected function casts(): array
    {
        return [
            'social_security_number' => 'encrypted',
            'birth_place' => 'encrypted',
            'italian_tax_code' => 'encrypted',
            'frontier_status_valid_from' => 'date:Y-m-d',
            'frontier_commune_in_zone' => 'boolean',
            'frontier_returns_daily' => 'boolean',
            'iban' => 'encrypted',
            'street' => 'encrypted',
            'house_number' => 'encrypted',
            'postal_code' => 'encrypted',
            'city' => 'encrypted',
            'church_tax' => 'boolean',
            'salary_installments_per_year' => 'integer',
            'hourly_wage' => 'decimal:4',
            'weekly_hours' => 'decimal:2',
            'holiday_weeks' => 'integer',
            'is_fixed_term_contract' => 'boolean',
            'is_withholding_tax_subject' => 'boolean',
            'withholding_tax_rate_percent' => 'decimal:4',
            'non_occupational_accident_rate_percent' => 'decimal:4',
            'occupational_accident_rate_percent' => 'decimal:4',
            'daily_sickness_rate_percent' => 'decimal:4',
            'family_allowance_fund_rate_percent' => 'decimal:4',
            'pension_fund_employee_rate_percent' => 'decimal:4',
            'regional_surcharge_rate_percent' => 'decimal:4',
            'municipal_surcharge_rate_percent' => 'decimal:4',
            'waive_retiree_allowance' => 'boolean',
            'extrapolate_qst_to_full_time' => 'boolean',
            'other_employment_fte_percent' => 'decimal:2',
            'qst_rate_determining_percent' => 'decimal:2',
            'frontier_apply_80_percent_basis' => 'boolean',
            'swiss_permit_number' => 'encrypted',
            'swiss_permit_valid_until' => 'date:Y-m-d',
            'single_parent_concubinage' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
