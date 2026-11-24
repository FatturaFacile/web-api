<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Models\WorkOrderItem;

class WorkOrderItemFactory extends Factory
{
    protected $model = WorkOrderItem::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'work_order_id' => WorkOrder::factory(),
            'item_type' => fake()->randomElement(['part', 'labor', 'expense']),
            'description' => fake()->sentence(3),
            'quantity' => 2.0,
            'unit_cost' => 25.00,
            'total_cost' => 50.00,
            'unit_price' => 45.00,
            'total_price' => 90.00,
            'is_billable' => true,
        ];
    }
}
