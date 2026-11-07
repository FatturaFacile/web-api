<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;

/**
 * @extends Factory<PayrollWithholdingTaxTariff>
 */
final class PayrollWithholdingTaxTariffFactory extends Factory
{
    protected $model = PayrollWithholdingTaxTariff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'canton' => 'TI',
            'tariff_code' => fake()->randomElement(['A0N', 'B1N', 'C0N', 'R0N']),
            'valid_from' => '2027-01-01',
            'income_from' => '6500.00',
            'income_step' => '100.00',
            'minimum_tax' => '0.00',
            'rate_percent' => '8.1500',
        ];
    }

    /**
     * Shared by every company.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null]);
    }
}
