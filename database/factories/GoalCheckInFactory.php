<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Goal;
use Modules\HumanResources\Models\GoalCheckIn;

/**
 * @extends Factory<GoalCheckIn>
 */
final class GoalCheckInFactory extends Factory
{
    protected $model = GoalCheckIn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'goal_id' => static fn (array $attributes): string => (string) Goal::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'key_result_id' => null,
            'value' => null,
            'progress' => 0,
            'comment' => null,
            'checked_in_at' => '2026-06-30 10:00:00',
        ];
    }
}
