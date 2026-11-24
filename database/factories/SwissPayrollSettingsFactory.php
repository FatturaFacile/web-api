<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\SwissPayrollSettings;

/**
 * @extends Factory<SwissPayrollSettings>
 */
final class SwissPayrollSettingsFactory extends Factory
{
    protected $model = SwissPayrollSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'withholding_debtor_numbers' => ['TI' => fake()->numerify('#######')],
            'ahv_employee_rate_percent' => '5.3000',
            'ahv_employer_rate_percent' => '5.3000',
            'ahv_administration_cost_percent' => '0.5000',
            'alv_employee_rate_percent' => '1.1000',
            'alv_employer_rate_percent' => '1.1000',
            'alv_annual_ceiling' => '148200.00',
            'uvg_annual_ceiling' => '148200.00',
            'nbu_employee_rate_percent' => '1.2000',
            'bu_employer_rate_percent' => '0.4500',
            'ktg_employee_rate_percent' => '0.5000',
            'ktg_employer_rate_percent' => '0.5000',
            'fak_employer_rate_percent' => '1.5000',
            'bvg_employer_share_percent' => '50.0000',
            'bvg_risk_rate_percent' => '1.0000',
            'pensioner_allowance_monthly' => '1400.00',
            'default_work_canton' => 'TI',
            'ahv_member_number' => fake()->numerify('100.###'),
            'uvg_insurer' => 'Suva',
            'bvg_insurer' => 'Fondazione collettiva LPP',
        ];
    }
}
