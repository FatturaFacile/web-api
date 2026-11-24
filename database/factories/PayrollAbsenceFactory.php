<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollAbsence;

/**
 * @extends Factory<PayrollAbsence>
 */
final class PayrollAbsenceFactory extends Factory
{
    protected $model = PayrollAbsence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'absence_type' => fake()->randomElement(PayrollAbsence::Types),
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-13',
            'percent' => '100.00',
            'note' => null,
        ];
    }
}
