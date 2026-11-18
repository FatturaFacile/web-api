<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\InventoryCostLayerConsumption;

/**
 * @extends Factory<InventoryCostLayerConsumption>
 */
class InventoryCostLayerConsumptionFactory extends Factory
{
    protected $model = InventoryCostLayerConsumption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $layer = InventoryCostLayer::factory()->create();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => (app(TenantContext::class)->getTenantId() ?? Tenant::first()?->id) ?? $layer->tenant_id,
            'layer_id' => $layer->id,
            'stock_item_id' => $layer->stock_item_id,
            'issue_movement_id' => (string) Str::uuid7(),
            'quantity' => 1,
            'unit_cost' => $layer->unit_cost,
            'cogs_amount' => round((float) $layer->unit_cost, 2),
            'consumed_at' => now(),
        ];
    }
}
