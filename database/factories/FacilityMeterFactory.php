<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Models\FacilityMeter;

/**
 * @extends Factory<FacilityMeter>
 */
final class FacilityMeterFactory extends Factory
{
    protected $model = FacilityMeter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'building_id' => static fn (array $attributes): string => (string) FacilityBuilding::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'kind' => 'electricity',
            'code' => 'M'.fake()->unique()->numerify('####'),
            'name' => 'Main meter',
            'unit' => 'kWh',
            'tariff_per_unit' => '0.250000',
            'currency' => 'EUR',
            'is_active' => true,
        ];
    }
}
