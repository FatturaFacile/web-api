<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgProductionCost;
use Modules\Manufacturing\Models\MfgProductionOrder;

/**
 * @extends Factory<MfgProductionCost>
 */
final class MfgProductionCostFactory extends Factory
{
    protected $model = MfgProductionCost::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'production_order_id' => MfgProductionOrder::factory(),
            'cost_type' => fake()->randomElement(['material', 'labor', 'machine', 'overhead']),
            'standard_cost' => fake()->randomFloat(4, 100, 1000),
            'actual_cost' => fake()->randomFloat(4, 100, 1000),
            'variance' => 0.0000,
            'is_posted_to_gl' => false,
        ];
    }
}
