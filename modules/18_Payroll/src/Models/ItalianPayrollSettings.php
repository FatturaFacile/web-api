<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;

/**
 * INPS and INAIL settings of an Italian company.
 */
final class ItalianPayrollSettings extends PayrollRecord
{
    use Auditable;

    protected $table = 'payroll_italian_settings';

    protected function casts(): array
    {
        return [
            'employee_inps_rate_percent' => 'decimal:4',
            'employer_inps_rate_percent' => 'decimal:4',
        ];
    }
}
