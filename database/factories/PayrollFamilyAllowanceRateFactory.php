<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;

/**
 * @extends Factory<PayrollFamilyAllowanceRate>
 */
final class PayrollFamilyAllowanceRateFactory extends Factory
{
    protected $model = PayrollFamilyAllowanceRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'canton' => 'TI',
            'valid_from' => '2027-01-01',
            'child_allowance' => '215.00',
            'education_allowance' => '268.00',
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
