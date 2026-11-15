<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Support\Decimal;

/**
 * Item valuation settings (response of `PUT valuation/items/{id}`).
 *
 * @mixin StockItem
 */
final class ValuationItemResource extends JsonResource
{
    private ?InventoryRevaluation $revaluation = null;

    public function withRevaluation(?InventoryRevaluation $revaluation): self
    {
        $this->revaluation = $revaluation;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockItem $item */
        $item = $this->resource;
        $method = app(InventoryCostingServiceInterface::class)->describeMethod($item);

        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'category_id' => $item->category_id,
            'valuation_method' => $method['item_method'],
            'category_method' => $method['category_method'],
            'effective_method' => $method['effective_method'],
            'source' => $method['source'],
            'standard_cost' => Decimal::unitCost((string) $item->standard_cost),
            'moving_average_cost' => Decimal::unitCost((string) $item->current_moving_average_cost),
            'weight_kg' => $item->weight_kg !== null ? Decimal::round((string) $item->weight_kg, 3) : null,
            'volume_m3' => $item->volume_m3 !== null ? Decimal::round((string) $item->volume_m3, 4) : null,
            'revaluation' => $this->revaluation !== null ? [
                'id' => $this->revaluation->id,
                'source_type' => $this->revaluation->source_type,
                'total_inventory_delta' => Decimal::money((string) $this->revaluation->total_inventory_delta),
                'total_cogs_delta' => Decimal::money((string) $this->revaluation->total_cogs_delta),
                'journal_entry_id' => $this->revaluation->journal_entry_id,
                'posting_date' => $this->revaluation->posting_date->toDateString(),
            ] : null,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
