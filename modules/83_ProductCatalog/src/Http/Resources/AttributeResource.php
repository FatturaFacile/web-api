<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductCatalog\Models\CatalogAttribute;
use Modules\ProductCatalog\Models\CatalogAttributeValue;

/**
 * An attribute (variant axis) with its values and their surcharges.
 *
 * @mixin CatalogAttribute
 */
final class AttributeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'sku_token' => $this->sku_token,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'values' => $this->whenLoaded('values', fn (): array => $this->values->map(static fn (CatalogAttributeValue $value): array => [
                'id' => $value->id,
                'code' => $value->code,
                'label' => $value->label,
                'sku_token' => $value->sku_token,
                'price_delta' => $value->price_delta,
                'price_delta_type' => $value->price_delta_type,
                'cost_delta' => $value->cost_delta,
                'cost_delta_type' => $value->cost_delta_type,
                'sort_order' => $value->sort_order,
                'is_active' => $value->is_active,
            ])->all()),
        ];
    }
}
