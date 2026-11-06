<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

/**
 * An absence of an employee, as the payroll sees it.
 */
final class PayrollAbsence extends PayrollRecord
{
    /** @var list<string> */
    public const array Types = ['Sickness', 'Accident', 'Maternity', 'Paternity', 'Military', 'Vacation', 'Unpaid', 'Other'];

    protected $table = 'payroll_absences';

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'percent' => 'decimal:2'];
    }
}
