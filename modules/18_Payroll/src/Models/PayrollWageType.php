<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A wage type of the company: which bases a line contributes to.
 */
final class PayrollWageType extends PayrollRecord
{
    use AllowsGlobalRecords, Auditable;

    protected $table = 'payroll_wage_types';

    protected function casts(): array
    {
        return [
            'subject_to_ahv' => 'boolean',
            'subject_to_alv' => 'boolean',
            'subject_to_uvg' => 'boolean',
            'subject_to_ktg' => 'boolean',
            'subject_to_bvg' => 'boolean',
            'subject_to_withholding_tax' => 'boolean',
            'is_irregular' => 'boolean',
            'sort_order' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
