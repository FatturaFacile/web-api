<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Wms\Models\WmsDockDoor;

/**
 * @extends Factory<WmsDockDoor>
 */
final class WmsDockDoorFactory extends Factory
{
    protected $model = WmsDockDoor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'code' => fake()->unique()->bothify('??-####'),
            'direction' => 'both',
            'is_active' => true,
        ];
    }
}
