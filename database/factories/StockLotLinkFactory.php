<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockLotLink;

/**
 * @extends Factory<StockLotLink>
 */
final class StockLotLinkFactory extends Factory
{
    protected $model = StockLotLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lot_id' => static fn (array $attributes): string => (string) StockLot::create([
                'tenant_id' => $attributes['tenant_id'],
                'stock_item_id' => StockItemFactory::new()->create(['tenant_id' => $attributes['tenant_id'], 'tracking_type' => 'lot'])->id,
                'lot_number' => strtoupper(fake()->unique()->bothify('LOT-2026-####')),
                'lot_level' => 'finished_good',
                'manufacturing_date' => '2026-10-20',
                'expiration_date' => '2028-10-19',
            ])->id,
            'component_lot_id' => static fn (array $attributes): string => (string) StockLot::create([
                'tenant_id' => $attributes['tenant_id'],
                'stock_item_id' => StockItemFactory::new()->create(['tenant_id' => $attributes['tenant_id'], 'tracking_type' => 'lot'])->id,
                'lot_number' => strtoupper(fake()->unique()->bothify('LOT-2026-####')),
                'lot_level' => 'raw_material',
                'supplier_lot_number' => fake()->bothify('SUP-######'),
                'received_at' => '2026-10-02',
            ])->id,
            'quantity_consumed' => '25.0000',
            'production_order_id' => null,
            'notes' => 'Lotto componente consumato in produzione',
        ];
    }
}
