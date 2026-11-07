<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollEmployeeChild;

/**
 * @extends Factory<PayrollEmployeeChild>
 */
final class PayrollEmployeeChildFactory extends Factory
{
    protected $model = PayrollEmployeeChild::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-15 years', '-1 year')->format('Y-m-d'),
            'in_education' => false,
            'education_end_date' => null,
            'allowance_eligible' => true,
        ];
    }
}
