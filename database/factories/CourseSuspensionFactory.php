<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseSuspension;

/**
 * @extends Factory<CourseSuspension>
 */
final class CourseSuspensionFactory extends Factory
{
    protected $model = CourseSuspension::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => static fn (array $attributes): string => Course::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'starts_on' => '2026-12-23',
            'ends_on' => '2027-01-06',
            'reason' => 'Vacanze di Natale',
        ];
    }
}
