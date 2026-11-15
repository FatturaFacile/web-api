<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Planning\Models\PlanningApproval;
use Modules\Planning\Models\PlanningScenario;

/**
 * @extends Factory<PlanningApproval>
 */
final class PlanningApprovalFactory extends Factory
{
    protected $model = PlanningApproval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'scenario_id' => PlanningScenario::factory(),
            'action' => PlanningApproval::Submit,
            'from_status' => PlanningScenario::Draft,
            'to_status' => PlanningScenario::Review,
        ];
    }
}
