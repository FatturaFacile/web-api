<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\StaffShift;

/**
 * @extends Factory<StaffShift>
 */
final class ChildcareStaffShiftFactory extends Factory
{
    protected $model = StaffShift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => static fn (): string => (string) User::factory()->create()->id,
            'group_id' => static fn (array $attributes): string => (string) ChildcareGroupFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'date' => fake()->dateTimeBetween('2026-09-01', '2026-12-18')->format('Y-m-d'),
            'starts_at' => '07:30',
            'ends_at' => '16:30',
            'break_minutes' => 45,
        ];
    }
}
