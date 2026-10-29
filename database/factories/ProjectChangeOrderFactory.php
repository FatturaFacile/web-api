<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectChangeOrder;

/**
 * @extends Factory<ProjectChangeOrder>
 */
class ProjectChangeOrderFactory extends Factory
{
    protected $model = ProjectChangeOrder::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'order_number' => 'CO-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(3),
            'scope_description' => fake()->paragraph(),
            'cost_impact_amount' => 5000.00,
            'revenue_impact_amount' => 7500.00,
            'schedule_impact_days' => 7,
            'status' => 'draft',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'customer_approved',
            'approved_by_client_at' => now(),
        ]);
    }
}
