<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;

/**
 * A line of a draft transfer order: nothing shipped or received yet.
 *
 * @extends Factory<TransferOrderLine>
 */
final class TransferOrderLineFactory extends Factory
{
    protected $model = TransferOrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transfer_order_id' => TransferOrderFactory::new(),
            'tenant_id' => static fn (array $attributes): string => (string) TransferOrder::query()->withoutGlobalScopes()->whereKey($attributes['transfer_order_id'])->value('tenant_id'),
            'stock_item_id' => static fn (array $attributes): string => (string) StockItemFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'lot_id' => null,
            'quantity' => '10.0000',
            'quantity_shipped' => '0.0000',
            'quantity_received' => '0.0000',
        ];
    }
}
