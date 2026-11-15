<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Wms\Models\WmsDockAppointment;

/**
 * @extends Factory<WmsDockAppointment>
 */
final class WmsDockAppointmentFactory extends Factory
{
    protected $model = WmsDockAppointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'dock_door_id' => WmsDockDoor::factory(),
            'warehouse_id' => Warehouse::factory(),
            'carrier_name' => fake()->unique()->bothify('??-####'),
            'direction' => 'inbound',
            'starts_at' => '2027-01-15 09:00:00',
            'ends_at' => '2027-01-15 09:00:00',
            'status' => 'scheduled',
        ];
    }
}
