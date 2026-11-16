<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HumanResources\Enums\Payroll\PayrollRunStatus;

/**
 * Monthly Swiss payroll run.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $run_number
 * @property int $period_year
 * @property int $period_month
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property \Illuminate\Support\Carbon|null $payment_date
 * @property PayrollRunStatus $status
 * @property string|null $rate_set_id
 * @property string $currency
 * @property list<string>|null $employee_ids
 * @property list<array{employee_id: string, wage_type_code: string, amount: string, label?: string|null}>|null $inputs
 * @property int $employee_count
 * @property int $gross_rappen
 * @property int $employee_deductions_rappen
 * @property int $employer_contributions_rappen
 * @property int $withholding_tax_rappen
 * @property int $net_rappen
 * @property \Illuminate\Support\Carbon|null $calculated_at
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $approved_by_user_id
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property string|null $closed_by_user_id
 * @property string|null $notes
 */
final class PayrollRun extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_payroll_runs';

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<PayrollRateSet, $this>
     */
    public function rateSet(): BelongsTo
    {
        return $this->belongsTo(PayrollRateSet::class, 'rate_set_id');
    }

    public function periodCode(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'payment_date' => 'date',
            'status' => PayrollRunStatus::class,
            'employee_ids' => 'array',
            'inputs' => 'array',
            'employee_count' => 'integer',
            'gross_rappen' => 'integer',
            'employee_deductions_rappen' => 'integer',
            'employer_contributions_rappen' => 'integer',
            'withholding_tax_rappen' => 'integer',
            'net_rappen' => 'integer',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
