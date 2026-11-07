<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\BidPosition;

/**
 * @extends Factory<BidPosition>
 */
final class TradesBidPositionFactory extends Factory
{
    protected $model = BidPosition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 20, 600);
        $unitPrice = fake()->randomFloat(2, 8, 45);
        $total = $quantity * $unitPrice;

        return [
            'tenant_id' => Tenant::factory(),
            'bid_id' => static fn (array $attributes): string => (string) TradesBidFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'position_code' => fake()->unique()->numerify('###.###'),
            'sort_order' => fake()->numberBetween(1, 50),
            'description' => fake()->randomElement([
                'Tinteggiatura pareti interne con idropittura lavabile, due mani',
                'Rasatura a gesso di pareti e soffitti',
                'Fondo isolante su intonaco nuovo',
                'Verniciatura serramenti in legno, smalto all\'acqua',
            ]),
            'unit' => 'm2',
            'quantity' => number_format($quantity, 4, '.', ''),
            'unit_price' => number_format($unitPrice, 4, '.', ''),
            'total' => number_format($total, 2, '.', ''),
            'components' => null,
            'budget_labour_hours' => number_format($quantity * 0.12, 2, '.', ''),
            'budget_labour_amount' => number_format($quantity * 0.12 * 72, 2, '.', ''),
            'budget_material_amount' => number_format($total * 0.25, 2, '.', ''),
            'budget_machine_amount' => '0.00',
        ];
    }
}
