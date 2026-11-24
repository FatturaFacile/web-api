<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsDossierDelivery;

/**
 * @extends Factory<CustomsDossierDelivery>
 */
final class CustomsDossierDeliveryFactory extends Factory
{
    protected $model = CustomsDossierDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'dossier_id' => static fn (array $attributes): string => (string) CustomsDossier::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'packages' => fake()->numberBetween(1, 30),
            'gross_weight_kg' => number_format(fake()->randomFloat(3, 20, 2000), 3, '.', ''),
            'delivered_on' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'recipient' => fake()->company(),
        ];
    }
}
