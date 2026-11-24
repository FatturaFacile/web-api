<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<CourseEnrolment>
 */
final class CourseEnrolmentFactory extends Factory
{
    protected $model = CourseEnrolment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => static fn (array $attributes): string => Course::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => CourseEnrolmentStatus::Enrolled,
            'waitlist_position' => null,
            'enrolled_on' => '2026-09-15',
        ];
    }

    public function waitlisted(int $position = 1): static
    {
        return $this->state(['status' => CourseEnrolmentStatus::Waitlisted, 'waitlist_position' => $position]);
    }
}
