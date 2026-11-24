<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\SiteCost;

/**
 * @extends Factory<SiteCost>
 */
final class TradesSiteCostFactory extends Factory
{
    protected $model = SiteCost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hours = fake()->randomElement([4, 6, 8]);
        $hourlyCost = 68.0;

        return [
            'tenant_id' => Tenant::factory(),
            'bid_id' => static fn (array $attributes): string => (string) TradesBidFactory::new()->create(['tenant_id' => $attributes['tenant_id'], 'status' => 'accepted'])->id,
            'bid_position_id' => null,
            'cost_type' => 'labour',
            'cost_date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'description' => 'Ore operaio pittore in cantiere',
            'quantity' => number_format($hours, 4, '.', ''),
            'unit_cost' => number_format($hourlyCost, 4, '.', ''),
            'amount' => number_format($hours * $hourlyCost, 2, '.', ''),
        ];
    }
}
