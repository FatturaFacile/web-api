<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Fleet\Models\FleetScheduledService;
use Modules\Fleet\Models\FleetVehicle;

/**
 * @extends Factory<FleetScheduledService>
 */
final class FleetScheduledServiceFactory extends Factory
{
    protected $model = FleetScheduledService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => FleetVehicle::factory(),
            'name' => 'Annual service',
            'service_type' => 'maintenance',
            'interval_days' => 365,
            'interval_km' => 15000,
            'next_due_on' => '2028-01-01',
            'next_due_km' => 25000,
            'is_active' => true,
        ];
    }
}
