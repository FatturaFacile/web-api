<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningScenario;

/**
 * @extends Factory<PlanningPlanLine>
 */
final class PlanningPlanLineFactory extends Factory
{
    protected $model = PlanningPlanLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'scenario_id' => PlanningScenario::factory(),
            'line_type' => PlanningPlanLine::Demand,
            'item_code' => 'ITEM-'.Str::upper(Str::random(4)),
            'item_name' => 'Item',
            'period' => '2028-01-01',
            'quantity' => '100.000',
            'source' => PlanningPlanLine::Manual,
        ];
    }
}
