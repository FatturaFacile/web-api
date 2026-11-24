<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

/**
 * A child of an employee, for the family allowances.
 */
final class PayrollEmployeeChild extends PayrollRecord
{
    protected $table = 'payroll_employee_children';

    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'date_of_birth' => 'date:Y-m-d',
            'in_education' => 'boolean',
            'education_end_date' => 'date:Y-m-d',
            'allowance_eligible' => 'boolean',
        ];
    }
}
