<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'stock_item_id' => fn (array $attributes): string => StockItem::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'warehouse_id' => fn (array $attributes): string => Warehouse::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'reserved_for_type' => 'sales_order',
            'reserved_for_id' => (string) Str::uuid(),
            'quantity_reserved' => fake()->randomFloat(2, 1, 20),
            'status' => 'active',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function released(): static
    {
        return $this->state(fn () => ['status' => 'released']);
    }
}
