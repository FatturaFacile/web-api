<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Requests;

use Illuminate\Validation\Rule;

final class BulkPriceListItemsRequest extends PricingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.product_id' => ['required', 'uuid', $this->ownRow('products')],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'items.*.min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.max_quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.discount_cascade' => ['nullable', 'string', 'max:120', 'regex:'.self::CascadePattern],
            'items.*.discount_mode' => ['nullable', Rule::in(['MULTIPLICATIVE', 'ADDITIVE'])],
            'items.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'items.*.valid_to' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
