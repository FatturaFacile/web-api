<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\LmsCourse;

/**
 * @extends Factory<LmsCourse>
 */
final class LmsCourseFactory extends Factory
{
    protected $model = LmsCourse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'C-'.strtoupper(Str::random(6)),
            'title' => fake()->sentence(3),
            'delivery' => 'online',
            'duration_hours' => '2.0',
            'is_mandatory' => false,
            'status' => LmsCourse::Published,
        ];
    }

    public function mandatory(?int $validityMonths = 12): self
    {
        return $this->state(['is_mandatory' => true, 'validity_months' => $validityMonths]);
    }
}
