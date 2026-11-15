<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\GoalCycle;

/**
 * @extends Factory<GoalCycle>
 */
final class GoalCycleFactory extends Factory
{
    protected $model = GoalCycle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Cycle '.fake()->unique()->numerify('####'),
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => GoalCycle::StatusActive,
        ];
    }

    public function draft(): self
    {
        return $this->state(['status' => GoalCycle::StatusDraft]);
    }

    public function closed(): self
    {
        return $this->state(['status' => GoalCycle::StatusClosed, 'closed_at' => '2026-12-31 18:00:00']);
    }
}
