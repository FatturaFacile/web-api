<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTimesheet;

/**
 * @extends Factory<ProjectTimesheet>
 */
class ProjectTimesheetFactory extends Factory
{
    protected $model = ProjectTimesheet::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();
        $userId = User::first()?->id ?? (string) Str::uuid7();
        $hours = fake()->randomFloat(2, 4, 8);
        $costRate = 45.00;
        $chargeRate = 95.00;

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'user_id' => $userId,
            'work_date' => now()->toDateString(),
            'hours_worked' => $hours,
            'overtime_hours' => 0.0,
            'cost_rate' => $costRate,
            'charge_rate' => $chargeRate,
            'total_cost' => round($hours * $costRate, 2),
            'total_charge' => round($hours * $chargeRate, 2),
            'status' => 'submitted',
            'is_billable' => true,
            'work_summary' => fake()->sentence(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => (string) Str::uuid7(),
        ]);
    }
}
