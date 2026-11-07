<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\ShiftSequence;
use Modules\HumanResources\Models\ShiftSequenceAssignment;

/**
 * @extends Factory<ShiftSequenceAssignment>
 */
final class ShiftSequenceAssignmentFactory extends Factory
{
    protected $model = ShiftSequenceAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'shift_sequence_id' => static fn (array $attributes): string => (string) ShiftSequence::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'start_date' => '2026-11-02',
            'end_date' => null,
            'offset_days' => fake()->numberBetween(0, 5),
        ];
    }
}
