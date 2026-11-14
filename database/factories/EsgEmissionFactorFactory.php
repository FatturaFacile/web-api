<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgEmissionFactor;

/**
 * @extends Factory<EsgEmissionFactor>
 */
final class EsgEmissionFactorFactory extends Factory
{
    protected $model = EsgEmissionFactor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'F-'.fake()->unique()->bothify('????-####'),
            'version' => 1,
            'name' => fake()->words(3, true),
            'activity_type' => 'electricity_grid',
            'unit' => 'kWh',
            'scope' => 2,
            'category' => 'purchased_electricity',
            'kg_co2e_per_unit' => '0.25000000',
            'region' => 'IT',
            'source' => 'Test source',
            'source_year' => 2024,
            'is_example' => false,
            'is_active' => true,
        ];
    }
}
