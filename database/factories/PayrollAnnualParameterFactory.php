<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollAnnualParameter;

/**
 * @extends Factory<PayrollAnnualParameter>
 */
final class PayrollAnnualParameterFactory extends Factory
{
    protected $model = PayrollAnnualParameter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'country' => 'CH',
            'year' => 2027,
            'parameters' => [
                'ahv_employee_rate_percent' => '5.3',
                'ahv_employer_rate_percent' => '5.3',
                'alv_employee_rate_percent' => '1.1',
                'alv_employer_rate_percent' => '1.1',
                'alv_annual_ceiling' => '148200',
                'uvg_annual_ceiling' => '148200',
                'bvg_entry_threshold' => '22680',
                'bvg_coordination_deduction' => '26460',
            ],
            'legal_reference' => 'UFAS, Modifiche al 1° gennaio 2027 nel settore dei contributi e delle prestazioni.',
        ];
    }

    /**
     * Shared by every company.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
