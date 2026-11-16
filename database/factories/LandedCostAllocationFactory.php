<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\LandedCostAllocation;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Models\StockItem;

/**
 * @extends Factory<LandedCostAllocation>
 */
class LandedCostAllocationFactory extends Factory
{
    protected $model = LandedCostAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $document = LandedCostDocument::factory()->create();
        $amount = (float) $document->total_amount;

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $document->tenant_id,
            'landed_cost_document_id' => $document->id,
            'purchase_receipt_id' => (string) Str::uuid7(),
            'stock_item_id' => StockItem::factory(),
            'valuation_method' => 'fifo',
            'basis_value' => 100,
            'allocated_amount' => $amount,
            'to_inventory_amount' => $amount,
            'to_cogs_amount' => 0,
            'to_variance_amount' => 0,
        ];
    }
}
