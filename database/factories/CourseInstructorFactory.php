<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<CourseInstructor>
 */
final class CourseInstructorFactory extends Factory
{
    protected $model = CourseInstructor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => static fn (array $attributes): string => Course::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'valid_from' => '2026-09-01',
            'valid_to' => '2027-06-30',
        ];
    }
}
