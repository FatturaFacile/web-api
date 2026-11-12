<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * The monthly family allowances of a canton from a date.
 */
final class PayrollFamilyAllowanceRate extends PayrollRecord
{
    use AllowsGlobalRecords, Auditable;

    protected $table = 'payroll_family_allowance_rates';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'child_allowance' => 'decimal:2',
            'education_allowance' => 'decimal:2',
        ];
    }
}
