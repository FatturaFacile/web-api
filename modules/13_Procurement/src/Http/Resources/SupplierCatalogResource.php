<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierPriceList;

/**
 * A supplier catalogue entry with its price list (quantity breaks and validity).
 *
 * @mixin SupplierCatalog
 */
final class SupplierCatalogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'supplier_id' => (string) $this->supplier_id,
            'supplier_part_number' => $this->supplier_part_number,
            'internal_sku' => $this->internal_sku,
            'name' => $this->name,
            'description' => $this->description,
            'uom' => $this->uom,
            'lead_time_days' => $this->lead_time_days,
            'minimum_order_quantity' => (string) $this->minimum_order_quantity,
            'is_active' => (bool) $this->is_active,
            'prices' => $this->whenLoaded('priceLists', fn () => $this->priceLists->map(static fn (SupplierPriceList $price): array => self::price($price))->all()),
        ];
    }

    /**
     * One price of the list.
     *
     * @return array{id: string, currency: string, min_quantity: string, unit_price: string, valid_from: ?string, valid_to: ?string}
     */
    public static function price(SupplierPriceList $price): array
    {
        return [
            'id' => (string) $price->id,
            'currency' => (string) $price->currency,
            'min_quantity' => (string) $price->min_quantity,
            'unit_price' => (string) $price->unit_price,
            'valid_from' => $price->valid_from?->toDateString(),
            'valid_to' => $price->valid_to?->toDateString(),
        ];
    }
}
