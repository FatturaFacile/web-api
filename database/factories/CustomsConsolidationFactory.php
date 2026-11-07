<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsConsolidation;

/**
 * @extends Factory<CustomsConsolidation>
 */
final class CustomsConsolidationFactory extends Factory
{
    protected $model = CustomsConsolidation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reference' => strtoupper(fake()->unique()->bothify('GRP-2026-#####')),
            'status' => 'open',
            'carrier_name' => fake()->randomElement(['Planzer Transport AG', 'Galliker Transport AG', 'Fercam SpA', 'Arcese Trasporti SpA']),
            'departure_on' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'freight_cost' => number_format(fake()->randomFloat(2, 800, 4500), 2, '.', ''),
            'currency' => 'CHF',
        ];
    }
}
