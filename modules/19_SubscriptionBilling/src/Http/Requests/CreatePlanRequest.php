<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\SubscriptionBilling\Services\PricingCalculator;
use Modules\SubscriptionBilling\Support\BillingCalendar;

/**
 * A new plan: interval, alignment, pricing model with its tiers, metered features, VAT rate and ledger accounts.
 */
final class CreatePlanRequest extends FormRequest
{
    use TenantRules;

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
            'plan_code' => ['required', 'string', 'max:50', $this->uniqueInTenant('sub_plans', 'plan_code')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'product_id' => ['nullable', 'uuid', $this->existsInTenant('sub_products')],
            'billing_interval' => ['required', 'string', 'in:'.implode(',', BillingCalendar::intervals())],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'billing_alignment' => ['nullable', 'string', 'in:anniversary,calendar'],
            'pricing_model' => ['nullable', 'string', 'in:'.implode(',', PricingCalculator::MODELS)],
            'currency' => ['nullable', 'string', 'size:3'],
            'setup_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trial_period_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'min_commitment_cycles' => ['nullable', 'integer', 'min:1', 'max:120'],
            'renewal_price_increase_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'revenue_account_id' => ['nullable', 'uuid', $this->existsInTenant('chart_of_accounts')],
            'deferred_revenue_account_id' => ['nullable', 'uuid', $this->existsInTenant('chart_of_accounts')],
            'tiers' => ['nullable', 'array', 'max:50'],
            'tiers.*.from_quantity' => ['required', 'numeric', 'min:0'],
            'tiers.*.to_quantity' => ['nullable', 'numeric', 'gt:0'],
            'tiers.*.unit_price' => ['required', 'numeric', 'min:0'],
            'tiers.*.flat_fee' => ['nullable', 'numeric', 'min:0'],
            'features' => ['nullable', 'array', 'max:50'],
            'features.*.feature_code' => ['required', 'string', 'max:50', 'distinct'],
            'features.*.name' => ['required', 'string', 'max:150'],
            'features.*.metric_type' => ['nullable', 'string', 'in:boolean,metered_quota,unlimited'],
            'features.*.included_quantity' => ['nullable', 'numeric', 'min:0'],
            'features.*.overage_unit_price' => ['nullable', 'numeric', 'min:0'],
            'translations' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Tiered and volume plans need a valid tier ladder.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $tiers = $this->input('tiers', []);
            $model = $this->input('pricing_model', 'flat_fee');

            if (in_array($model, ['tiered', 'volume'], true) && empty($tiers)) {
                $validator->errors()->add('tiers', __('A tiered or volume plan needs its pricing tiers.'));

                return;
            }

            if (is_array($tiers) && ($error = app(PricingCalculator::class)->tierLadderError($tiers)) !== null) {
                $validator->errors()->add('tiers', $error);
            }
        }];
    }
}
