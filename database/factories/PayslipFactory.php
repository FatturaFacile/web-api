<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;

/**
 * @extends Factory<Payslip>
 */
class PayslipFactory extends Factory
{
    protected $model = Payslip::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'payroll_run_id' => fn (array $attributes) => PayrollRun::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'employee_id' => fn (array $attributes) => Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'payroll_profile_id' => null,
            'rate_set_id' => (string) Str::uuid7(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'days_worked' => 30,
            'fte_percentage' => '100.00',
            'gross_rappen' => 800_000,
            'ahv_base_rappen' => 800_000,
            'alv_base_rappen' => 800_000,
            'alv_solidarity_base_rappen' => 0,
            'bvg_insured_salary_rappen' => 6_426_000,
            'uvg_base_rappen' => 800_000,
            'qst_base_rappen' => 0,
            'withholding_tax_rappen' => 0,
            'employee_deductions_rappen' => 91_575,
            'employer_contributions_rappen' => 92_175,
            'net_rappen' => 708_425,
            'snapshot' => [],
        ];
    }
}
