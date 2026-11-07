<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\ItalianPayrollSettings;

/**
 * @extends Factory<ItalianPayrollSettings>
 */
final class ItalianPayrollSettingsFactory extends Factory
{
    protected $model = ItalianPayrollSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'inps_company_number' => fake()->numerify('49########'),
            'inps_office_code' => '4900',
            'inail_code' => fake()->numerify('########'),
            'employee_inps_rate_percent' => '9.1900',
            'employer_inps_rate_percent' => '29.0000',
        ];
    }
}
