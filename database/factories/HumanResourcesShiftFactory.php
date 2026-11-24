<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftType;

/**
 * @extends Factory<Shift>
 */
final class HumanResourcesShiftFactory extends Factory
{
    protected $model = Shift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workDate = fake()->dateTimeBetween('2026-12-01', '2026-12-31')->format('Y-m-d');

        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => static fn (array $attributes): string => (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'department_id' => null,
            'shift_type_id' => static fn (array $attributes): string => (string) ShiftType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'work_date' => $workDate,
            'starts_at' => $workDate.' 08:00:00',
            'ends_at' => $workDate.' 17:00:00',
            'break_minutes' => 60,
            'planned_minutes' => 480,
            'status' => Shift::StatusDraft,
            'source' => Shift::SourceManual,
            'shift_publication_id' => null,
            'note' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => Shift::StatusPublished]);
    }
}
