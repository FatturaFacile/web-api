<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;

/**
 * @extends Factory<MfgProductionOrderLine>
 */
final class MfgProductionOrderLineFactory extends Factory
{
    protected $model = MfgProductionOrderLine::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'production_order_id' => MfgProductionOrder::factory(),
            'mfg_item_id' => MfgItem::factory()->rawMaterial(),
            'required_quantity' => fake()->randomFloat(4, 10, 50),
            'allocated_quantity' => 10.0000,
            'consumed_quantity' => 0.0000,
            'scrapped_quantity' => 0.0000,
            'uom' => 'PCS',
            'unit_cost' => fake()->randomFloat(4, 5, 20),
            'total_cost' => 100.0000,
        ];
    }
}
