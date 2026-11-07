<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Volunteer;

/**
 * @extends Factory<Volunteer>
 */
class VolunteerFactory extends Factory
{
    protected $model = Volunteer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => fn (): string => Member::factory()->create()->id,
            'activity' => fake()->randomElement(['Driver', 'Cook', 'First aid', 'Coach assistant']),
            'started_on' => now()->subYear()->startOfYear()->toDateString(),
            'is_occasional' => false,
            'insurer' => 'Mutual insurer',
            'insurance_policy' => fake()->bothify('POL-#####'),
            'insurance_expires_on' => now()->addYear()->toDateString(),
        ];
    }

    public function occasional(): static
    {
        return $this->state(['is_occasional' => true]);
    }
}
