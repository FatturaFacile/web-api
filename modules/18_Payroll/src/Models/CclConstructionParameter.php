<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * The construction figures of a collective agreement from a date, with the document they come from. minimum_wages holds
 * for each qualification and zone a monthly and an hourly minimum.
 */
final class CclConstructionParameter extends PayrollRecord
{
    use AllowsGlobalRecords;

    protected $table = 'payroll_ccl_construction_parameters';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'annual_hours' => 'decimal:2',
            'accounting_year_start_month' => 'integer',
            'minimum_wages' => 'array',
        ];
    }
}
