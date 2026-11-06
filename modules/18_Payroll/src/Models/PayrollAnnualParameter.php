<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;

/**
 * The legal values of a country for a year.
 */
final class PayrollAnnualParameter extends PayrollRecord
{
    use Auditable;

    protected $table = 'payroll_annual_parameters';

    protected function casts(): array
    {
        return ['year' => 'integer', 'parameters' => 'array'];
    }
}
