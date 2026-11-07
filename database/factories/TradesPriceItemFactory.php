<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\PriceItem;

/**
 * @extends Factory<PriceItem>
 */
final class TradesPriceItemFactory extends Factory
{
    protected $model = PriceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->numerify('###.###');

        return [
            'tenant_id' => Tenant::factory(),
            'price_list_id' => static fn (array $attributes): string => (string) TradesPriceListFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'position_code' => $code,
            'parent_code' => substr($code, 0, 3),
            'unit' => 'm2',
            'unit_price' => number_format(fake()->randomFloat(2, 6, 40), 4, '.', ''),
            'labour_minutes' => number_format(fake()->randomFloat(1, 4, 20), 2, '.', ''),
            'material_cost' => number_format(fake()->randomFloat(2, 1, 8), 4, '.', ''),
            'machine_cost' => '0.0000',
            'descriptions' => [
                'it' => 'Tinteggiatura pareti interne con idropittura, due mani',
                'de' => 'Innenwände mit Dispersionsfarbe streichen, zwei Anstriche',
            ],
        ];
    }
}
