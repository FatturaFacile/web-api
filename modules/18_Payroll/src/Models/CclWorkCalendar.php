<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

/**
 * The hours the company calendar plans for a month under a collective agreement.
 */
final class CclWorkCalendar extends PayrollRecord
{
    protected $table = 'payroll_ccl_work_calendars';

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'planned_hours' => 'decimal:2'];
    }
}
