<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Models\FleetVehicleAssignment;

/**
 * @extends Factory<FleetVehicleAssignment>
 */
final class FleetVehicleAssignmentFactory extends Factory
{
    protected $model = FleetVehicleAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => FleetVehicle::factory(),
            'driver_label' => fake()->name(),
            'starts_on' => '2027-01-01',
            'start_odometer_km' => 10000,
        ];
    }
}
