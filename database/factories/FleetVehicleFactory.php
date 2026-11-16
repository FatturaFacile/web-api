<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Fleet\Models\FleetVehicle;

/**
 * @extends Factory<FleetVehicle>
 */
final class FleetVehicleFactory extends Factory
{
    protected $model = FleetVehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'license_plate' => Str::upper(Str::random(7)),
            'make' => fake()->randomElement(['Fiat', 'Volkswagen', 'Renault', 'Ford']),
            'model' => fake()->word(),
            'vehicle_type' => 'car',
            'fuel_type' => 'diesel',
            'status' => FleetVehicle::Active,
            'initial_odometer_km' => 10000,
            'odometer_km' => 10000,
            'ownership' => 'owned',
            'currency' => 'EUR',
        ];
    }
}
