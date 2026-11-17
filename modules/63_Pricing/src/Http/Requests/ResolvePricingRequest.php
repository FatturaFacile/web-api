<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Requests;

use Illuminate\Validation\Rule;

final class ResolvePricingRequest extends PricingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'uuid', $this->ownRow('contacts')],
            'currency' => ['required', 'string', 'size:3'],
            'channel' => ['nullable', Rule::in(['DEFAULT', 'B2B', 'RETAIL', 'ECOMMERCE'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid', $this->ownRow('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.manual_discount_cascade' => ['nullable', 'string', 'max:120', 'regex:'.self::CascadePattern],
        ];
    }
}
