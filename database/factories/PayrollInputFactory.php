<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollInput;

/**
 * @extends Factory<PayrollInput>
 */
final class PayrollInputFactory extends Factory
{
    protected $model = PayrollInput::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'year' => 2026,
            'month' => 11,
            'wage_type_code' => '1200',
            'quantity' => '8.0000',
            'rate' => '45.5000',
            'amount' => '364.00',
            'note' => 'Ore straordinarie novembre',
        ];
    }
}
