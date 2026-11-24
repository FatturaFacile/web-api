<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Models\StaffingNeed;

/**
 * @extends Factory<StaffingNeed>
 */
final class StaffingNeedFactory extends Factory
{
    protected $model = StaffingNeed::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'department_id' => static fn (array $attributes): string => (string) Department::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'shift_type_id' => static fn (array $attributes): string => (string) ShiftType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'day_kind' => fake()->randomElement(StaffingNeed::DayKinds),
            'required_count' => fake()->numberBetween(1, 6),
            'valid_from' => '2027-01-01',
            'valid_to' => null,
        ];
    }
}
