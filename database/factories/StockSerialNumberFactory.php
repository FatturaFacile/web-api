<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockSerialNumber;

/**
 * @extends Factory<StockSerialNumber>
 */
class StockSerialNumberFactory extends Factory
{
    protected $model = StockSerialNumber::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'stock_item_id' => fn (array $attributes): string => StockItem::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'serial_number' => 'SN-'.strtoupper(fake()->unique()->bothify('??####??')),
            'status' => 'in_stock',
        ];
    }
}
