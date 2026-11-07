<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Hospitality\Models\OrderItem;

/**
 * @extends Factory<OrderItem>
 */
final class HospitalityOrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dish = fake()->randomElement([
            ['Risotto ai funghi porcini', '26.0000'],
            ['Tagliata di manzo con rucola', '38.5000'],
            ['Polenta e brasato', '29.0000'],
            ['Insalata mista', '9.5000'],
        ]);

        return [
            'tenant_id' => Tenant::factory(),
            'order_id' => static fn (array $attributes): string => (string) HospitalityOrderFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'product_id' => null,
            'description' => $dish[0],
            'quantity' => number_format(fake()->numberBetween(1, 3), 4, '.', ''),
            'unit_price' => $dish[1],
            'tax_rate' => '8.10',
            'course' => 1,
            'station' => 'kitchen',
            'notes' => null,
            'status' => 'pending',
            'paid_quantity' => '0.0000',
        ];
    }
}
