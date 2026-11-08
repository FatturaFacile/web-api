<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Models\TransferOrderLineLot;

/**
 * The lot-less stock shipped for a transfer order line, not received yet.
 *
 * @extends Factory<TransferOrderLineLot>
 */
final class TransferOrderLineLotFactory extends Factory
{
    protected $model = TransferOrderLineLot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transfer_order_line_id' => TransferOrderLineFactory::new(),
            'tenant_id' => static fn (array $attributes): string => (string) TransferOrderLine::query()->withoutGlobalScopes()->whereKey($attributes['transfer_order_line_id'])->value('tenant_id'),
            'lot_id' => null,
            'quantity_shipped' => '10.0000',
            'quantity_received' => '0.0000',
        ];
    }
}
