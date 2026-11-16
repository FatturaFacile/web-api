<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Models\StockItem;

/**
 * Row of `GET valuation/config`.
 *
 * @mixin StockItem
 */
final class ValuationConfigResource extends JsonResource
{
    /**
     * @return array{item_id: string, sku: string, category_id: string|null, category_method: string|null, item_method: string|null, effective_method: string, source: string}
     */
    public function toArray(Request $request): array
    {
        /** @var StockItem $item */
        $item = $this->resource;
        $method = app(InventoryCostingServiceInterface::class)->describeMethod($item);

        return [
            'item_id' => (string) $item->id,
            'sku' => (string) $item->sku,
            'category_id' => $item->category_id !== null ? (string) $item->category_id : null,
            'category_method' => $method['category_method'],
            'item_method' => $method['item_method'],
            'effective_method' => $method['effective_method'],
            'source' => $method['source'],
        ];
    }
}
