<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\ScheduleOccurrence;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseSchedule;

/**
 * @extends Factory<CourseSchedule>
 */
final class CourseScheduleFactory extends Factory
{
    protected $model = CourseSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startHour = fake()->numberBetween(15, 20);

        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => static fn (array $attributes): string => Course::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'pattern' => ScheduleOccurrence::Weekday,
            'weekday' => fake()->numberBetween(1, 5),
            'on_date' => null,
            'starts_at' => sprintf('%02d:00:00', $startHour),
            'ends_at' => sprintf('%02d:00:00', $startHour + 1),
        ];
    }

    public function onDate(string $date): static
    {
        return $this->state(['pattern' => ScheduleOccurrence::Date, 'weekday' => null, 'on_date' => $date]);
    }
}
