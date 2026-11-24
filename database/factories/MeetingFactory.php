<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\MeetingBody;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Models\Meeting;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => MeetingBody::Assembly,
            'title' => 'Ordinary assembly '.fake()->numberBetween(1, 999),
            'scheduled_at' => now()->addWeeks(2),
            'location' => fake()->city(),
            'status' => MeetingStatus::Draft,
            'proxies_allowed' => false,
        ];
    }

    public function board(): static
    {
        return $this->state(['body' => MeetingBody::Board, 'title' => 'Board meeting']);
    }
}
