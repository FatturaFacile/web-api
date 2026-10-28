<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;

/**
 * @extends Factory<ProjectBudget>
 */
class ProjectBudgetFactory extends Factory
{
    protected $model = ProjectBudget::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();
        $revenue = 100000.00;
        $cost = 75000.00;

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'status' => 'draft',
            'total_revenue_budget' => $revenue,
            'total_cost_budget' => $cost,
            'labour_budget' => 30000.00,
            'material_budget' => 25000.00,
            'supplier_budget' => 15000.00,
            'fleet_budget' => 3000.00,
            'expense_budget' => 1000.00,
            'contingency_budget' => 1000.00,
            'projected_margin_amount' => 25000.00,
            'projected_margin_percentage' => 25.00,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn () => [
            'status' => 'pending_approval',
            'total_cost_budget' => 150000.00,
        ]);
    }
}
