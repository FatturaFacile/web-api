<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Rebates\Models\RebatePromotion;

/**
 * A promotion: a percentage or fixed discount in a date range, optionally for one product or customer.
 */
final class PromotionRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'discount_type' => ['required', Rule::in(RebatePromotion::DiscountTypes)],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'product_id' => ['nullable', 'uuid', $this->ownRow('products')],
            'contact_id' => ['nullable', 'uuid', $this->ownRow('contacts')],
            'min_quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? [...$rules, 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']] : $rules;
    }

    /**
     * A percentage discount cannot exceed 100.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('discount_type') === RebatePromotion::Percentage && (float) $this->input('discount_value', 0) > 100) {
                $validator->errors()->add('discount_value', __('rebate_errors.promotion.percentage_max'));
            }
        }];
    }
}
