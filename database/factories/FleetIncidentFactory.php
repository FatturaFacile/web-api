<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Fleet\Models\FleetIncident;
use Modules\Fleet\Models\FleetVehicle;

/**
 * @extends Factory<FleetIncident>
 */
final class FleetIncidentFactory extends Factory
{
    protected $model = FleetIncident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => FleetVehicle::factory(),
            'kind' => 'fine',
            'occurred_on' => '2027-03-01',
            'description' => 'Speeding',
            'amount' => '85.00',
            'currency' => 'EUR',
            'payment_status' => FleetIncident::Unpaid,
        ];
    }
}
