<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the co-products and by-products of a draft BOM revision with their cost shares.
 */
final class ReplaceCoProductsRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'co_products' => ['present', 'array', 'max:20'],
            'co_products.*.product_item_id' => ['required', 'uuid', 'distinct', $this->tenantOwns('mfg_items')],
            'co_products.*.product_type' => ['nullable', 'string', 'in:co_product,by_product'],
            'co_products.*.output_ratio_qty' => ['required', ...self::PositiveQuantity],
            'co_products.*.cost_allocation_percentage' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }
}
