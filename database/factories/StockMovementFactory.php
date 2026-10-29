<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();
        $qty = fake()->randomFloat(2, 1, 50);
        $cost = fake()->randomFloat(2, 10, 100);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'warehouse_id' => Warehouse::factory(),
            'stock_item_id' => StockItem::factory(),
            'movement_number' => 'MOV-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('?###')),
            'movement_type' => 'INTERNAL_TRANSFER',
            'quantity' => $qty,
            'unit_cost' => $cost,
            'total_valuation' => $qty * $cost,
            'created_by' => (string) Str::uuid(),
        ];
    }
}
