<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Requests;

use Illuminate\Validation\Rule;

final class PriceListItemRequest extends PricingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'product_id' => ['required', 'uuid', $this->ownRow('products')],
                'price' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            ]),
            'min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'max_quantity' => ['nullable', 'numeric', 'gt:0', 'gte:min_quantity'],
            'discount_cascade' => ['nullable', 'string', 'max:120', 'regex:'.self::CascadePattern],
            'discount_mode' => ['nullable', Rule::in(['MULTIPLICATIVE', 'ADDITIVE'])],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
