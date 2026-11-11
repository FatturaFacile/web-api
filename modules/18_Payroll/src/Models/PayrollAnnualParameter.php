<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\Foundation\Concerns\Auditable;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * The legal values of a country for a year.
 */
final class PayrollAnnualParameter extends PayrollRecord
{
    use AllowsGlobalRecords, Auditable, BelongsToTenant;

    protected $table = 'payroll_annual_parameters';

    protected function casts(): array
    {
        return ['year' => 'integer', 'parameters' => 'array'];
    }
}
