<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\ShiftException;
use Modules\HumanResources\Models\ShiftType;

/**
 * @extends Factory<ShiftException>
 */
final class ShiftExceptionFactory extends Factory
{
    protected $model = ShiftException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'exception_date' => '2026-12-24',
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'department_id' => null,
            'shift_type_id' => static fn (array $attributes): string => (string) ShiftType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'reason' => 'Cambio turno concordato con il responsabile',
        ];
    }
}
