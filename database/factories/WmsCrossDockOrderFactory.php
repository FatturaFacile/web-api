<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Wms\Models\WmsCrossDockOrder;

/**
 * @extends Factory<WmsCrossDockOrder>
 */
final class WmsCrossDockOrderFactory extends Factory
{
    protected $model = WmsCrossDockOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'number' => 'CD-2027-'.fake()->unique()->numerify('####'),
            'inbound_reference' => fake()->unique()->bothify('??-####'),
            'outbound_reference' => fake()->unique()->bothify('??-####'),
            'status' => 'planned',
        ];
    }
}
