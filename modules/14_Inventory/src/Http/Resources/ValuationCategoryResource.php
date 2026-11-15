<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Models\StockItemCategory;

/**
 * @mixin StockItemCategory
 */
final class ValuationCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockItemCategory $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'valuation_method' => (ValuationMethod::fromStorage($category->valuation_method) ?? ValuationMethod::WeightedAverage)->value,
            'inventory_account_id' => $category->inventory_account_id,
            'cogs_account_id' => $category->cogs_account_id,
            'ppv_account_id' => $category->ppv_account_id,
            'landed_cost_clearing_account_id' => $category->landed_cost_clearing_account_id,
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }
}
