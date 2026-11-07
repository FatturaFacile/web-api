<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Childcare\Models\Attendance;

/**
 * @extends Factory<Attendance>
 */
final class ChildcareAttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = Carbon::instance(fake()->dateTimeBetween('2026-09-01', '2026-12-18'))->startOfDay();

        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'date' => $date->toDateString(),
            'status' => 'present',
            'absence_reason' => null,
            'check_in_at' => $date->copy()->setTime(7, 45),
            'check_out_at' => $date->copy()->setTime(16, 30),
            'temperature' => null,
        ];
    }

    /**
     * The child stayed at home, sick.
     */
    public function absent(): self
    {
        return $this->state(['status' => 'absent', 'absence_reason' => 'sick', 'check_in_at' => null, 'check_out_at' => null]);
    }
}
