<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Fleet\Models\FleetDeadline;
use Modules\Fleet\Models\FleetVehicle;

/**
 * @extends Factory<FleetDeadline>
 */
final class FleetDeadlineFactory extends Factory
{
    protected $model = FleetDeadline::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => FleetVehicle::factory(),
            'deadline_type' => 'insurance',
            'expires_on' => '2027-12-31',
            'cost' => '600.00',
            'currency' => 'EUR',
            'status' => FleetDeadline::Open,
        ];
    }
}
