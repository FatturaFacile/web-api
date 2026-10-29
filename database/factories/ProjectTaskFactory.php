<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTask;

/**
 * @extends Factory<ProjectTask>
 */
class ProjectTaskFactory extends Factory
{
    protected $model = ProjectTask::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'task_number' => 'TSK-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => 'todo',
            'priority' => 'medium',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addWeeks(2)->toDateString(),
            'estimated_hours' => fake()->randomFloat(2, 8, 80),
            'actual_hours' => 0.0,
            'progress_percentage' => 0.0,
            'is_milestone_driver' => false,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'actual_start_date' => now()->toDateString(),
            'progress_percentage' => 50.0,
            'actual_hours' => 20.0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'actual_start_date' => now()->subWeeks(2)->toDateString(),
            'actual_end_date' => now()->toDateString(),
            'progress_percentage' => 100.0,
            'actual_hours' => 40.0,
        ]);
    }
}
