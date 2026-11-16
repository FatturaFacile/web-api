<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Planning\Models\PlanningScenario;

/**
 * @extends Factory<PlanningScenario>
 */
final class PlanningScenarioFactory extends Factory
{
    protected $model = PlanningScenario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Baseline '.Str::random(5),
            'scenario_type' => PlanningScenario::Baseline,
            'status' => PlanningScenario::Draft,
            'horizon_start' => '2028-01-01',
            'horizon_end' => '2028-06-30',
        ];
    }
}
