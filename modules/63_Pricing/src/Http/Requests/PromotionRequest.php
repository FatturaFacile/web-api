<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\Pricing\Models\Promotion;

final class PromotionRequest extends PricingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ignore = $this->route('id');

        return [
            ...$this->forMethod([
                'code' => ['required', 'string', 'max:40', Rule::unique('promotions', 'code')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($ignore)],
                'name' => ['required', 'string', 'max:160'],
                'type' => ['required', Rule::in(Promotion::Types)],
                'rules' => ['required', 'array'],
            ]),
            'rules.product_id' => ['nullable', 'uuid', $this->ownRow('products')],
            'rules.trigger_product_id' => ['nullable', 'uuid', $this->ownRow('products')],
            'rules.gift_product_id' => ['nullable', 'uuid', $this->ownRow('products')],
            'rules.product_ids' => ['nullable', 'array'],
            'rules.product_ids.*' => ['uuid', $this->ownRow('products')],
            'rules.vat_treatment' => ['nullable', Rule::in([Promotion::WithVatRecourse, Promotion::WithoutVatRecourse])],
            'rules.buy_qty' => ['nullable', 'integer', 'min:2'],
            'rules.pay_qty' => ['nullable', 'integer', 'min:1', 'lt:rules.buy_qty'],
            'rules.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rules.tiers' => ['nullable', 'array'],
            'rules.tiers.*.min_amount' => ['required_with:rules.tiers', 'numeric', 'min:0'],
            'rules.tiers.*.discount_percent' => ['required_with:rules.tiers', 'numeric', 'min:0', 'max:100'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_stackable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The rules keep every key the promotion type needs, not only the ones validated above.
     *
     * @param  array-key|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['rules'] = $this->input('rules', $data['rules'] ?? []);

        return $key === null ? $data : data_get($data, $key, $default);
    }
}
