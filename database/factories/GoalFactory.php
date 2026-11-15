<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Goal;
use Modules\HumanResources\Models\GoalCycle;

/**
 * @extends Factory<Goal>
 */
final class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'goal_cycle_id' => static fn (array $attributes): string => (string) GoalCycle::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'parent_goal_id' => null,
            'level' => Goal::LevelEmployee,
            'employee_id' => static fn (array $attributes): ?string => ($attributes['level'] ?? Goal::LevelEmployee) === Goal::LevelEmployee
                ? (string) Employee::factory()->create(['tenant_id' => $attributes['tenant_id']])->id
                : null,
            'team_id' => null,
            'title' => 'Goal '.fake()->unique()->numerify('####'),
            'description' => null,
            'weight' => 1,
            'due_on' => '2026-12-31',
            'status' => Goal::StatusActive,
            'progress' => 0,
        ];
    }

    public function company(): self
    {
        return $this->state(['level' => Goal::LevelCompany, 'employee_id' => null]);
    }
}
