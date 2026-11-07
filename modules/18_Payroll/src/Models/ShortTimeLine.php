<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Employee;

/**
 * An employee in a monthly claim.
 */
final class ShortTimeLine extends PayrollRecord
{
    protected $table = 'payroll_short_time_lines';

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'is_eligible' => 'boolean',
            'target_hours' => 'decimal:2',
            'worked_hours' => 'decimal:2',
            'absence_hours' => 'decimal:2',
            'overtime_deducted_hours' => 'decimal:2',
            'lost_hours' => 'decimal:2',
            'waiting_hours' => 'decimal:2',
            'compensable_hours' => 'decimal:2',
            'insured_monthly_salary' => 'decimal:2',
            'lost_earnings' => 'decimal:2',
            'compensation' => 'decimal:2',
            'wage_reduction' => 'decimal:2',
            'employer_refund' => 'decimal:2',
        ];
    }
}
