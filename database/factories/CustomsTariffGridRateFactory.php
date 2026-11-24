<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\RateBasis;
use Modules\Customs\Models\CustomsTariffGrid;
use Modules\Customs\Models\CustomsTariffGridRate;

/**
 * @extends Factory<CustomsTariffGridRate>
 */
final class CustomsTariffGridRateFactory extends Factory
{
    protected $model = CustomsTariffGridRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'tariff_grid_id' => static fn (array $attributes): string => (string) CustomsTariffGrid::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'destination_country' => fake()->randomElement(['IT', 'DE', 'FR', 'AT']),
            'min_weight_kg' => '0.000',
            'max_weight_kg' => '1000.000',
            'min_volume_m3' => '0.000',
            'max_volume_m3' => null,
            'basis' => RateBasis::PerKg,
            'rate' => number_format(fake()->randomFloat(4, 0.15, 1.20), 4, '.', ''),
            'minimum_charge' => '85.00',
        ];
    }
}
