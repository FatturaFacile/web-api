<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product variant with its option attributes, lifecycle flag and calculated stock.
 *
 * @mixin Product
 */
class ProductVariantResource extends JsonResource
{
    public function __construct(Product $resource, private readonly ?float $stock = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'price' => (float) $this->sale_price,
            'cost_price' => (float) $this->cost_price,
            'tax_rate' => (float) $this->tax_rate,
            'min_stock' => $this->min_stock === null ? null : (float) $this->min_stock,
            'attributes' => $this->optionAttributes(),
            'discontinue_on_zero_stock' => $this->discontinuesOnZeroStock(),
            'track_inventory' => $this->track_inventory,
            'is_active' => $this->is_active,
            'stock' => $this->stock,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
