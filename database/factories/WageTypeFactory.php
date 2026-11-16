<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Payroll\WageType;

/**
 * @extends Factory<WageType>
 */
class WageTypeFactory extends Factory
{
    protected $model = WageType::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => (string) fake()->unique()->numberBetween(8000, 8999),
            'name' => 'Custom wage type',
            'category' => 'allowance',
            'is_ahv_subject' => true,
            'is_alv_subject' => true,
            'is_bvg_subject' => false,
            'is_uvg_subject' => true,
            'is_qst_subject' => true,
            'elm_code' => 'Salary',
            'is_active' => true,
            'sort_order' => 100,
        ];
    }

    public function baseSalary(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => (string) config('payroll_ch.base_salary_wage_type', '1000'),
            'name' => 'Monthly salary',
            'category' => 'salary',
            'is_bvg_subject' => true,
        ]);
    }

    public function notSubjectToInsurances(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_ahv_subject' => false,
            'is_alv_subject' => false,
            'is_bvg_subject' => false,
            'is_uvg_subject' => false,
            'is_qst_subject' => false,
        ]);
    }

    public function expense(): static
    {
        return $this->notSubjectToInsurances()->state(fn (array $attributes) => ['category' => 'expense', 'name' => 'Expense reimbursement']);
    }

    public function netDeduction(): static
    {
        return $this->notSubjectToInsurances()->state(fn (array $attributes) => ['category' => 'net_deduction', 'name' => 'Net deduction']);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
