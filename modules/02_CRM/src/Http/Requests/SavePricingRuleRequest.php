<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CRM\Models\PricingRule;

/**
 * A CPQ pricing rule.
 */
final class SavePricingRuleRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:150'],
            'rule_type' => ['required', Rule::in(PricingRule::Types)],
            'min_quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'partner_tier_code' => ['nullable', 'string', 'max:40'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
