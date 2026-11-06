<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\CourseKind;
use Modules\Associations\Models\Course;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => CourseKind::Recurring,
            'title' => 'Swimming for beginners',
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(6)->toDateString(),
            'status' => Course::Active,
        ];
    }
}
