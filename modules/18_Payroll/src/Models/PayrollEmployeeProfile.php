<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Employee;

/**
 * The payroll data of an employee that hr_employees does not hold.
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
