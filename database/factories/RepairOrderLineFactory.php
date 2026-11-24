<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\RepairOrderLine;

/**
 * @extends Factory<RepairOrderLine>
 */
final class RepairOrderLineFactory extends Factory
{
    protected $model = RepairOrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'repair_order_id' => static fn (array $attributes): string => (string) RepairOrderFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'stock_item_id' => static fn (array $attributes): string => (string) StockItemFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'name' => 'Spazzole motore ricambio',
            ])->id,
            'lot_id' => null,
            'quantity' => '2.0000',
            'unit_cost' => '7.5000',
            'line_total' => '15.00',
            'stock_movement_id' => null,
        ];
    }
}
