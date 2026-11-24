<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsQuoteLine;

/**
 * @extends Factory<CustomsQuoteLine>
 */
final class CustomsQuoteLineFactory extends Factory
{
    protected $model = CustomsQuoteLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 40, 900);

        return [
            'tenant_id' => Tenant::factory(),
            'quote_id' => static fn (array $attributes): string => (string) CustomsQuoteFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'description' => fake()->randomElement(['Trasporto stradale Milano - Chiasso', 'Sdoganamento import', 'Diritti di sosta magazzino', 'Assicurazione merce']),
            'quantity' => number_format($quantity, 3, '.', ''),
            'unit_price' => number_format($unitPrice, 4, '.', ''),
            'line_total' => number_format($quantity * $unitPrice, 2, '.', ''),
        ];
    }
}
