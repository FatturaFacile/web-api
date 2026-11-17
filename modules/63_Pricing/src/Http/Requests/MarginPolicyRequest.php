<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Pricing\Models\MarginPolicy;

final class MarginPolicyRequest extends PricingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'scope' => ['required', Rule::in(MarginPolicy::Scopes)],
                'min_margin_percent' => ['required', 'numeric', 'min:-100', 'max:100'],
            ]),
            'scope_id' => ['nullable', 'uuid', 'required_unless:scope,'.MarginPolicy::Global],
            'cost_basis' => ['nullable', Rule::in([MarginPolicy::LastPurchaseCost, MarginPolicy::AverageCost])],
            'block_on_violation' => ['sometimes', 'boolean'],
            'requires_approval_role' => ['nullable', 'string', 'max:40'],
        ];
    }
}
