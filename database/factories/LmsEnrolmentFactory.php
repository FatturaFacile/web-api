<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Models\LmsEnrolment;

/**
 * @extends Factory<LmsEnrolment>
 */
final class LmsEnrolmentFactory extends Factory
{
    protected $model = LmsEnrolment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'course_id' => LmsCourse::factory(),
            'employee_id' => (string) Str::uuid7(),
            'status' => LmsEnrolment::Enrolled,
            'progress_percent' => 0,
        ];
    }
}
