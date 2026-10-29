<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;

/**
 * @extends Factory<StockQuant>
 */
class StockQuantFactory extends Factory
{
    protected $model = StockQuant::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();
        $qty = fake()->randomFloat(2, 10, 100);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'warehouse_bin_id' => WarehouseBin::factory(),
            'stock_item_id' => StockItem::factory(),
            'quantity_on_hand' => $qty,
            'quantity_reserved' => 0.0,
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn () => [
            'quantity_on_hand' => 2.0,
            'quantity_reserved' => 0.0,
        ]);
    }

    public function reserved(float $reservedQty = 5.0): static
    {
        return $this->state(fn () => [
            'quantity_reserved' => $reservedQty,
        ]);
    }
}
