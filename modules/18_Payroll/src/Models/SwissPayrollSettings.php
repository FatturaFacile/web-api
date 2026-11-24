<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;

/**
 * Company settings of the Swiss payroll.
 */
final class SwissPayrollSettings extends PayrollRecord
{
    use Auditable;

    protected $table = 'payroll_swiss_settings';

    protected function casts(): array
    {
        return [
            'withholding_debtor_numbers' => 'array',
            'ahv_employee_rate_percent' => 'decimal:4',
            'ahv_employer_rate_percent' => 'decimal:4',
            'ahv_administration_cost_percent' => 'decimal:4',
            'alv_employee_rate_percent' => 'decimal:4',
            'alv_employer_rate_percent' => 'decimal:4',
            'alv_annual_ceiling' => 'decimal:2',
            'uvg_annual_ceiling' => 'decimal:2',
            'nbu_employee_rate_percent' => 'decimal:4',
            'bu_employer_rate_percent' => 'decimal:4',
            'ktg_employee_rate_percent' => 'decimal:4',
            'ktg_employer_rate_percent' => 'decimal:4',
            'fak_employer_rate_percent' => 'decimal:4',
            'bvg_employer_share_percent' => 'decimal:4',
            'bvg_risk_rate_percent' => 'decimal:4',
            'pensioner_allowance_monthly' => 'decimal:2',
        ];
    }
}
