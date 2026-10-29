<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\WorkOrder;

class PartsConsumptionFactory extends Factory
{
    protected $model = PartsConsumption::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'work_order_id' => WorkOrder::factory(),
            'product_id' => (string) Str::uuid7(),
            'quantity' => 1.0,
            'unit_cost' => 45.00,
            'total_cost' => 45.00,
            'lot_number' => 'LOT-'.strtoupper(Str::random(5)),
        ];
    }
}
