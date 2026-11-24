<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\Measurement;

/**
 * @extends Factory<Measurement>
 */
final class TradesMeasurementFactory extends Factory
{
    protected $model = Measurement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'bid_id' => static fn (array $attributes): string => (string) TradesBidFactory::new()->create(['tenant_id' => $attributes['tenant_id'], 'status' => 'accepted'])->id,
            'bid_position_id' => static fn (array $attributes): string => (string) TradesBidPositionFactory::new()->create(['tenant_id' => $attributes['tenant_id'], 'bid_id' => $attributes['bid_id']])->id,
            'measured_on' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'quantity' => number_format(fake()->randomFloat(2, 5, 150), 4, '.', ''),
            'note' => 'Misurato in cantiere con la direzione lavori.',
        ];
    }
}
