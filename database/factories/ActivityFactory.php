<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Activity;

/**
 * @extends Factory<Activity>
 */
final class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subject' => fake()->sentence(4),
            'type' => fake()->randomElement(['task', 'call', 'meeting', 'note', 'email', 'follow_up']),
            'status' => 'pending',
            'priority' => 'medium',
            'due_date' => now()->addDays(3),
            'duration_minutes' => 30,
            'notes' => fake()->paragraph(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
            'outcome' => 'Successful demo conducted and next steps agreed.',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'due_date' => now()->addDays(2),
        ]);
    }

    public function meeting(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'meeting',
            'location' => 'Executive Boardroom / Google Meet',
            'duration_minutes' => 60,
        ]);
    }

    public function call(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'call',
            'duration_minutes' => 15,
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'urgent',
            'due_date' => now()->addHours(4),
        ]);
    }
}
