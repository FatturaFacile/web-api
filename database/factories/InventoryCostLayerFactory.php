<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\StockItem;

/**
 * @extends Factory<InventoryCostLayer>
 */
class InventoryCostLayerFactory extends Factory
{
    protected $model = InventoryCostLayer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();
        $quantity = fake()->randomFloat(0, 1, 50);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'stock_item_id' => StockItem::factory(),
            'source_type' => 'receipt',
            'received_at' => now(),
            'layer_sequence' => 1,
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => fake()->randomFloat(4, 1, 100),
            'landed_cost_unit' => 0,
            'currency' => 'EUR',
        ];
    }

    public function depleted(): static
    {
        return $this->state(fn () => ['remaining_quantity' => 0]);
    }

    public function forReceipt(string $receiptId): static
    {
        return $this->state(fn () => ['receipt_id' => $receiptId, 'source_type' => 'receipt']);
    }
}
