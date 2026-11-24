<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SpecialPriceRequest extends PricingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'customer_id' => ['required', 'uuid', $this->ownRow('contacts')],
                'product_id' => ['required', 'uuid', $this->ownRow('products')],
                'currency' => ['required', 'string', 'size:3'],
            ]),
            'fixed_price' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'discount_cascade' => ['nullable', 'string', 'max:120', 'regex:'.self::CascadePattern],
            'discount_mode' => ['nullable', Rule::in(['MULTIPLICATIVE', 'ADDITIVE'])],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->isMethod('POST') && $this->input('fixed_price') === null && blank($this->input('discount_cascade'))) {
                $validator->errors()->add('fixed_price', 'A special price needs a fixed price or a discount cascade.');
            }
        });
    }
}
