<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\RmaLine;

/**
 * @extends Factory<RmaLine>
 */
class RmaLineFactory extends Factory
{
    protected $model = RmaLine::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'rma_order_id' => DepotRepairRmaOrder::factory(),
            'tenant_id' => fn (array $attributes) => DepotRepairRmaOrder::withoutGlobalScopes()->find($attributes['rma_order_id'])?->tenant_id,
            'line_type' => 'part',
            'description' => 'Replacement power supply',
            'qty' => 1,
            'unit_cost' => 30.00,
            'unit_price' => 55.00,
            'charged_to' => 'customer',
        ];
    }
}
