<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'product_id' => ['nullable', 'uuid'],
            'billing_interval' => ['required', 'string', 'in:monthly,quarterly,semiannual,annual,multi_year'],
            'interval_count' => ['nullable', 'integer', 'min:1'],
            'pricing_model' => ['nullable', 'string', 'in:flat_fee,per_unit,tiered,volume,metered,hybrid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'setup_fee' => ['nullable', 'numeric', 'min:0'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'trial_period_days' => ['nullable', 'integer', 'min:0'],
            'min_commitment_cycles' => ['nullable', 'integer', 'min:1'],
            'tiers' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
            'translations' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
