<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Attendance;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<Attendance>
 */
final class AssociationsAttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => static fn (array $attributes): string => Course::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'attended_on' => fake()->dateTimeBetween('2026-09-01', '2027-06-30')->format('Y-m-d'),
            'status' => Attendance::Present,
        ];
    }

    public function absent(): static
    {
        return $this->state(['status' => Attendance::Absent]);
    }
}
