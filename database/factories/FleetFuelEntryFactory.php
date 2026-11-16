<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Fleet\Models\FleetFuelEntry;
use Modules\Fleet\Models\FleetVehicle;

/**
 * @extends Factory<FleetFuelEntry>
 */
final class FleetFuelEntryFactory extends Factory
{
    protected $model = FleetFuelEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => FleetVehicle::factory(),
            'entry_date' => '2027-01-15',
            'energy_unit' => 'liter',
            'quantity' => '40.000',
            'unit_price' => '1.8000',
            'total_cost' => '72.00',
            'currency' => 'EUR',
            'odometer_km' => 10500,
            'is_full_tank' => true,
        ];
    }
}
