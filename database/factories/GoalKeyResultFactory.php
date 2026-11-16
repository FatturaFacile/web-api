<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Goal;
use Modules\HumanResources\Models\GoalKeyResult;

/**
 * @extends Factory<GoalKeyResult>
 */
final class GoalKeyResultFactory extends Factory
{
    protected $model = GoalKeyResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'goal_id' => static fn (array $attributes): string => (string) Goal::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'metric' => 'Metric '.fake()->unique()->numerify('####'),
            'unit' => '%',
            'start_value' => 0,
            'target_value' => 100,
            'current_value' => 0,
            'progress' => 0,
            'sort_order' => 0,
        ];
    }
}
