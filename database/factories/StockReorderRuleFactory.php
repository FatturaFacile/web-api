<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<StockReorderRule>
 */
class StockReorderRuleFactory extends Factory
{
    protected $model = StockReorderRule::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'warehouse_id' => Warehouse::factory(),
            'stock_item_id' => StockItem::factory(),
            'min_stock_level' => 10.0,
            'max_stock_level' => 50.0,
            'reorder_point' => 15.0,
            'economic_order_quantity' => 30.0,
            'lead_time_days' => 7,
            'auto_trigger_requisition' => true,
        ];
    }
}
