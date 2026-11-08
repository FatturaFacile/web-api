<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\PurchaseSuggestion;

/**
 * A purchase suggestion from a reorder rule or an MRP planned order.
 *
 * @mixin PurchaseSuggestion
 */
final class PurchaseSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'source' => $this->source,
            'source_id' => $this->source_id,
            'stock_item_id' => $this->stock_item_id,
            'warehouse_id' => $this->warehouse_id,
            'supplier_id' => $this->supplier_id,
            'supplier_catalog_id' => $this->supplier_catalog_id,
            'description' => $this->description,
            'quantity' => (string) $this->quantity,
            'uom' => $this->uom,
            'unit_price' => $this->unit_price !== null ? (string) $this->unit_price : null,
            'required_by_date' => $this->required_by_date?->toDateString(),
            'status' => $this->status,
            'purchase_order_id' => $this->purchase_order_id,
        ];
    }
}
