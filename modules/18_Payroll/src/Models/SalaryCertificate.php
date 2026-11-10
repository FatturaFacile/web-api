<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Employee;

/**
 * The annual Swiss salary certificate, generated from the closed runs. It is not the official form with the barcode.
 */
final class SalaryCertificate extends PayrollRecord
{
    protected $table = 'payroll_salary_certificates';

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
            'year' => 'integer',
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'checkbox_f_transport' => 'boolean',
            'checkbox_g_canteen' => 'boolean',
            'box1_salary' => 'decimal:2',
            'box2_benefits' => 'decimal:2',
            'box3_irregular' => 'decimal:2',
            'box4_capital' => 'decimal:2',
            'box7_other' => 'decimal:2',
            'box8_gross' => 'decimal:2',
            'box9_social_contributions' => 'decimal:2',
            'box10_1_ordinary_pension' => 'decimal:2',
            'box10_2_pension_buyin' => 'decimal:2',
            'box10_pension' => 'decimal:2',
            'box11_net' => 'decimal:2',
            'box12_withholding_tax' => 'decimal:2',
            'box13_1_effective_expenses' => 'decimal:2',
            'box13_2_lump_sum_expenses' => 'decimal:2',
            'box13_expenses' => 'decimal:2',
            'generated_at' => 'datetime',
        ];
    }
}
