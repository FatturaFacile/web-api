<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HumanResources\Models\Employee;

/**
 * Calculated Swiss payslip of one employee in one payroll run.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $payroll_run_id
 * @property string $employee_id
 * @property string|null $payroll_profile_id
 * @property string $rate_set_id
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property int $days_worked
 * @property string $fte_percentage
 * @property int $gross_rappen
 * @property int $ahv_base_rappen
 * @property int $alv_base_rappen
 * @property int $alv_solidarity_base_rappen
 * @property int $bvg_insured_salary_rappen
 * @property int $uvg_base_rappen
 * @property int $qst_base_rappen
 * @property string|null $qst_tariff_code
 * @property string|null $qst_canton
 * @property int $withholding_tax_rappen
 * @property int $employee_deductions_rappen
 * @property int $employer_contributions_rappen
 * @property int $net_rappen
 * @property array<string, mixed>|null $snapshot
 * @property-read PayrollRun|null $run
 * @property-read Employee|null $employee
 * @property-read PayrollProfile|null $profile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PayslipLine> $lines
 */
final class Payslip extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_payslips';

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<PayrollProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(PayrollProfile::class, 'payroll_profile_id');
    }

    /**
     * @return HasMany<PayslipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class, 'payslip_id')->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'days_worked' => 'integer',
            'fte_percentage' => 'decimal:2',
            'gross_rappen' => 'integer',
            'ahv_base_rappen' => 'integer',
            'alv_base_rappen' => 'integer',
            'alv_solidarity_base_rappen' => 'integer',
            'bvg_insured_salary_rappen' => 'integer',
            'uvg_base_rappen' => 'integer',
            'qst_base_rappen' => 'integer',
            'withholding_tax_rappen' => 'integer',
            'employee_deductions_rappen' => 'integer',
            'employer_contributions_rappen' => 'integer',
            'net_rappen' => 'integer',
            'snapshot' => 'array',
        ];
    }
}
