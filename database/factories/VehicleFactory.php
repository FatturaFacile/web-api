<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'license_plate' => strtoupper(fake()->unique()->bothify('TI ######')),
            'vin_chassis' => strtoupper(fake()->bothify('WVW##########????')),
            'make' => fake()->randomElement(['Fiat', 'Volkswagen', 'Toyota', 'Skoda']),
            'model' => fake()->word(),
            'year' => fake()->numberBetween(2010, 2026),
            'mileage_km' => fake()->numberBetween(1000, 200000),
        ];
    }
}
