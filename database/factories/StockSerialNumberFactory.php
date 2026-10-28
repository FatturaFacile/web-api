<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<StockSerialNumber>
 */
class StockSerialNumberFactory extends Factory
{
    protected $model = StockSerialNumber::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'stock_item_id' => StockItem::factory(),
            'serial_number' => 'SN-'.strtoupper(fake()->unique()->bothify('??####??')),
            'status' => 'in_stock',
            'current_warehouse_id' => Warehouse::factory(),
        ];
    }
}
