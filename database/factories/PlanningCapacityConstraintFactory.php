<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Planning\Models\PlanningCapacityConstraint;
use Modules\Planning\Models\PlanningResource;
use Modules\Planning\Models\PlanningScenario;

/**
 * @extends Factory<PlanningCapacityConstraint>
 */
final class PlanningCapacityConstraintFactory extends Factory
{
    protected $model = PlanningCapacityConstraint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'scenario_id' => PlanningScenario::factory(),
            'resource_id' => PlanningResource::factory(),
            'period' => '2028-01-01',
            'available_capacity' => '120.000',
        ];
    }
}
