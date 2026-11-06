<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollEmployeeProfile;

/**
 * @extends Factory<PayrollEmployeeProfile>
 */
class PayrollEmployeeProfileFactory extends Factory
{
    protected $model = PayrollEmployeeProfile::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'salary_installments_per_year' => 13,
            'work_canton' => 'TI',
        ];
    }
}
