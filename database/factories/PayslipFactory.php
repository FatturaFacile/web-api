<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;

/**
 * @extends Factory<Payslip>
 */
final class PayslipFactory extends Factory
{
    protected $model = Payslip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payroll_run_id' => static fn (array $attributes): string => (string) PayrollRun::factory()->create(['tenant_id' => $attributes['tenant_id'], 'year' => 2026, 'month' => 11])->id,
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'year' => 2026,
            'month' => 11,
            'employee_name' => fake()->name(),
            'paid_days' => '30.0000',
            'gross_salary' => '6500.00',
            'ahv_basis' => '6500.00',
            'alv_basis' => '6500.00',
            'uvg_basis' => '6500.00',
            'ktg_basis' => '6500.00',
            'bvg_insured_salary' => '3950.00',
            'withholding_tax_basis' => '0.00',
            'withholding_tax_rate_percent' => '0.0000',
            'withholding_tax_code' => null,
            'withholding_tax_canton' => null,
            'employee_deductions' => '812.50',
            'net_salary' => '5687.50',
            'payout_amount' => '5687.50',
            'employer_contributions' => '975.00',
            'warnings' => null,
        ];
    }
}
