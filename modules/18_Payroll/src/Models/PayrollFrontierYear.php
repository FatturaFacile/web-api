<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

/**
 * The nights a frontier worker did not return home for work in a calendar year.
 */
final class PayrollFrontierYear extends PayrollRecord
{
    protected $table = 'payroll_frontier_years';

    protected function casts(): array
    {
        return ['year' => 'integer', 'nights_away' => 'integer'];
    }
}
