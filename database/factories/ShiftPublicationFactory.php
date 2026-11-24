<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\ShiftPublication;

/**
 * @extends Factory<ShiftPublication>
 */
final class ShiftPublicationFactory extends Factory
{
    protected $model = ShiftPublication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'period_from' => '2026-12-07',
            'period_to' => '2026-12-13',
            'department_id' => static fn (array $attributes): string => (string) Department::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'shift_count' => fake()->numberBetween(20, 60),
            'employee_count' => fake()->numberBetween(4, 12),
            'violations' => [],
            'acknowledgement_reason' => null,
            'published_by' => null,
            'published_at' => '2026-12-01 09:00:00',
        ];
    }
}
