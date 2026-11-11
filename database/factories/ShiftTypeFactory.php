<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Services\Shifts\ShiftCategory;

/**
 * @extends Factory<ShiftType>
 */
final class ShiftTypeFactory extends Factory
{
    protected $model = ShiftType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shift = fake()->randomElement([
            ['Mattino', '06:00:00', '14:00:00', '#F5A623'],
            ['Pomeriggio', '14:00:00', '22:00:00', '#4A90E2'],
            ['Giornata', '08:00:00', '17:00:00', '#7ED321'],
        ]);

        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('T??##')),
            'name' => $shift[0],
            'category' => ShiftCategory::Working,
            'start_time' => $shift[1],
            'end_time' => $shift[2],
            'break_minutes' => 30,
            'is_paid_absence' => false,
            'color' => $shift[3],
            'is_active' => true,
        ];
    }

    public function night(): static
    {
        return $this->state(['name' => 'Notte', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'color' => '#4A4A4A']);
    }

    public function absence(): static
    {
        return $this->state(['name' => 'Ferie', 'category' => ShiftCategory::Absence, 'start_time' => null, 'end_time' => null, 'break_minutes' => 0, 'is_paid_absence' => true]);
    }

    /**
     * Shared by every company.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
