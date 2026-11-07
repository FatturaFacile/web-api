<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDeclarationItem;

/**
 * @extends Factory<CustomsDeclarationItem>
 */
final class CustomsDeclarationItemFactory extends Factory
{
    protected $model = CustomsDeclarationItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $netMass = fake()->randomFloat(3, 5, 800);

        return [
            'tenant_id' => Tenant::factory(),
            'declaration_id' => static fn (array $attributes): string => (string) CustomsDeclaration::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'line_number' => fake()->unique()->numberBetween(1, 999999),
            'trader_item_id' => strtoupper(fake()->bothify('ART-######')),
            'description' => fake()->randomElement(['Computer portatili', 'Macchine da caffè espresso', 'Componenti in acciaio inox', 'Calzature in pelle']),
            'commodity_code' => fake()->numerify('########'),
            'statistical_code' => 911,
            'gross_mass' => number_format($netMass * 1.08, 3, '.', ''),
            'net_mass' => number_format($netMass, 3, '.', ''),
            'is_repair' => false,
            'statistical_value' => number_format(fake()->randomFloat(2, 500, 30000), 2, '.', ''),
            'duty_rate' => '0.00',
            'origin_country' => fake()->randomElement(['IT', 'DE', 'FR', 'AT']),
            'preference' => true,
            'packaging_type' => 'CT',
            'package_quantity' => fake()->numberBetween(1, 40),
            'produced_documents' => [],
        ];
    }
}
