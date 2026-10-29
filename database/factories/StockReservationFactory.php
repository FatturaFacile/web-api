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
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'stock_item_id' => StockItem::factory(),
            'warehouse_id' => Warehouse::factory(),
            'source_document_type' => 'sales_order',
            'source_document_id' => (string) Str::uuid(),
            'quantity_reserved' => fake()->randomFloat(2, 1, 20),
            'status' => 'active',
            'allocated_by' => (string) Str::uuid(),
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
