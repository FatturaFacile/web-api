<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Fleet\Models\FleetServiceRecord;
use Modules\Fleet\Models\FleetVehicle;

/**
 * @extends Factory<FleetServiceRecord>
 */
final class FleetServiceRecordFactory extends Factory
{
    protected $model = FleetServiceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => FleetVehicle::factory(),
            'service_type' => 'maintenance',
            'service_date' => '2027-02-01',
            'odometer_km' => 11000,
            'description' => 'Oil and filter change',
            'cost' => '180.00',
            'currency' => 'EUR',
        ];
    }
}
