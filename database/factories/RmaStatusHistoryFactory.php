<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\RmaStatusHistory;

/**
 * @extends Factory<RmaStatusHistory>
 */
class RmaStatusHistoryFactory extends Factory
{
    protected $model = RmaStatusHistory::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'rma_order_id' => DepotRepairRmaOrder::factory(),
            'tenant_id' => fn (array $attributes) => DepotRepairRmaOrder::withoutGlobalScopes()->find($attributes['rma_order_id'])?->tenant_id,
            'from_status' => null,
            'to_status' => 'awaiting_arrival',
            'actor_type' => 'user',
        ];
    }
}
