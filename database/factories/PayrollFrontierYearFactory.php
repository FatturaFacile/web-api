<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollFrontierYear;

/**
 * @extends Factory<PayrollFrontierYear>
 */
final class PayrollFrontierYearFactory extends Factory
{
    protected $model = PayrollFrontierYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'year' => 2026,
            'nights_away' => fake()->numberBetween(0, 45),
        ];
    }
}
