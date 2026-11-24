<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\Concerns\FrozenByClosedRun;

/**
 * A payslip. In Italian payrolls ahv_basis holds the INPS basis and withholding_tax_basis the IRPEF taxable income,
 * as in the desktop schema.
 */
final class Payslip extends PayrollRecord
{
    use FrozenByClosedRun;

    protected $table = 'payroll_payslips';

    /**
     * @param  Builder<self>  $query
     * @param  list<string>  $employeeIds
     * @return Builder<self>
     */
    public function scopeForEmployees(Builder $query, array $employeeIds): Builder
    {
        return $query->whereIn('employee_id', $employeeIds);
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayslipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class, 'payslip_id')->orderBy('sort_order')->orderBy('wage_type_code');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }

    protected function owningRun(): ?PayrollRun
    {
        return PayrollRun::query()->find($this->getOriginal('payroll_run_id') ?? $this->payroll_run_id);
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'paid_days' => 'decimal:4',
            'gross_salary' => 'decimal:2',
            'ahv_basis' => 'decimal:2',
            'alv_basis' => 'decimal:2',
            'uvg_basis' => 'decimal:2',
            'ktg_basis' => 'decimal:2',
            'bvg_insured_salary' => 'decimal:2',
            'withholding_tax_basis' => 'decimal:2',
            'withholding_tax_rate_percent' => 'decimal:4',
            'employee_deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'employer_contributions' => 'decimal:2',
        ];
    }
}
