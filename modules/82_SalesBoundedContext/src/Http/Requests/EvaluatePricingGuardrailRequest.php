<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EvaluatePricingGuardrailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rule_code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'size:3'],
            'customer_segment' => ['nullable', 'string', 'max:64'],
            'base_unit_price' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'cascading_discounts' => ['nullable', 'array'],
            'cascading_discounts.*' => ['numeric', 'min:0', 'max:100'],
            'tier_breaks' => ['nullable', 'array'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'promo_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_margin_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
