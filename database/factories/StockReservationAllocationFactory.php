<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\StockReservationAllocation;

/**
 * @extends Factory<StockReservationAllocation>
 */
final class StockReservationAllocationFactory extends Factory
{
    protected $model = StockReservationAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'stock_reservation_id' => static fn (array $attributes): string => (string) StockReservation::create([
                'tenant_id' => $attributes['tenant_id'],
                'stock_item_id' => StockItemFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
                'warehouse_id' => WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
                'reserved_for_type' => 'sales_order',
                'reserved_for_id' => (string) Str::uuid(),
                'quantity_reserved' => '5.0000',
                'status' => 'active',
            ])->id,
            'stock_quant_id' => static fn (array $attributes): string => (string) StockQuantFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'stock_item_id' => StockReservation::query()->withoutGlobalScopes()->whereKey($attributes['stock_reservation_id'])->value('stock_item_id'),
            ])->id,
            'lot_id' => null,
            'warehouse_bin_id' => static fn (array $attributes): string => (string) StockQuant::query()->withoutGlobalScopes()->whereKey($attributes['stock_quant_id'])->value('warehouse_bin_id'),
            'quantity' => '5.0000',
        ];
    }
}
