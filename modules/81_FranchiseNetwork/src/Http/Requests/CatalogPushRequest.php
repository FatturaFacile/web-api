<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FranchiseNetwork\Domain\Enums\PriceType;
use Modules\FranchiseNetwork\Domain\Enums\PushType;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class CatalogPushRequest extends FormRequest
{
    use ScopesToTenant;

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
            'push_type' => ['required', Rule::in(PushType::values())],
            'title' => ['required', 'string', 'max:200'],
            'target_mode' => ['required', Rule::in(['all', 'selected'])],
            'store_ids' => ['required_if:target_mode,selected', 'nullable', 'array', 'min:1'],
            'store_ids.*' => ['uuid', $this->tenantExists('frn_stores')],
            'effective_from' => ['nullable', 'date'],
            'dispatch' => ['sometimes', 'boolean'],
            'payload' => ['required', 'array'],
            'payload.items' => ['required_if:push_type,catalog', 'array', 'min:1', 'max:5000'],
            'payload.items.*.sku' => ['required_with:payload.items', 'string', 'max:60'],
            'payload.items.*.name' => ['required_with:payload.items', 'string', 'max:200'],
            'payload.items.*.price' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.price_type' => ['nullable', Rule::in(PriceType::values())],
            'payload.items.*.variants' => ['nullable', 'array'],
            'payload.items.*.images' => ['nullable', 'array'],
            'payload.items.*.recipe' => ['nullable', 'array'],
            'payload.prices' => ['required_if:push_type,price_list', 'array', 'min:1', 'max:5000'],
            'payload.prices.*.sku' => ['required_with:payload.prices', 'string', 'max:60'],
            'payload.prices.*.price' => ['required_with:payload.prices', 'numeric', 'min:0'],
            'payload.prices.*.price_type' => ['nullable', Rule::in(PriceType::values())],
            'payload.promotion' => ['required_if:push_type,promotion', 'array'],
            'payload.promotion.name' => ['required_with:payload.promotion', 'string', 'max:200'],
            'payload.promotion.valid_from' => ['required_with:payload.promotion', 'date'],
            'payload.promotion.valid_to' => ['required_with:payload.promotion', 'date', 'after_or_equal:payload.promotion.valid_from'],
            'payload.promotion.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payload.loyalty' => ['required_if:push_type,loyalty_campaign', 'array'],
            'payload.loyalty.name' => ['required_with:payload.loyalty', 'string', 'max:200'],
            'payload.loyalty.points_multiplier' => ['required_with:payload.loyalty', 'numeric', 'min:0', 'max:100'],
            'payload.loyalty.valid_from' => ['nullable', 'date'],
            'payload.loyalty.valid_to' => ['nullable', 'date'],
        ];
    }
}
