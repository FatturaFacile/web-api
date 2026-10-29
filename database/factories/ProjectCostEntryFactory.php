<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;

/**
 * @extends Factory<ProjectCostEntry>
 */
class ProjectCostEntryFactory extends Factory
{
    protected $model = ProjectCostEntry::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();
        $qty = fake()->randomFloat(2, 1, 20);
        $unitCost = fake()->randomFloat(2, 20, 250);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'cost_category' => fake()->randomElement(['material', 'labour', 'supplier', 'fleet', 'expense']),
            'cost_source_type' => 'manual_entry',
            'cost_source_id' => (string) Str::uuid7(),
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => round($qty * $unitCost, 2),
            'incurred_date' => now()->toDateString(),
            'uom' => 'PCE',
            'is_billable' => true,
        ];
    }

    public function material(): static
    {
        return $this->state(fn () => [
            'cost_category' => 'material',
            'cost_source_type' => 'inventory_movement',
        ]);
    }

    public function labour(): static
    {
        return $this->state(fn () => [
            'cost_category' => 'labour',
            'cost_source_type' => 'timesheet',
            'uom' => 'HUR',
        ]);
    }
}
