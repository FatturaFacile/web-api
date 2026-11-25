<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\SubscriptionLifecycleAction;

final class SubscriptionActionRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'contract_code' => ['nullable', 'string', 'max:40'],
            'plan_name' => ['nullable', 'string', 'max:160'],
            'billing_cycle' => ['nullable', Rule::in(['monthly', 'quarterly', 'annual'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'base_recurring_amount' => ['nullable', 'numeric', 'min:0'],
            'pro_rata_active_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            'pro_rata_period_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'indexation_type' => ['nullable', Rule::in(['ISTAT_FOI', 'SWISS_LIK'])],
            'action' => ['nullable', Rule::in(SubscriptionLifecycleAction::values())],
            'new_amount' => ['nullable', 'numeric', 'min:0'],
            'index_rate_pct' => ['nullable', 'numeric', 'min:-50', 'max:100'],
            'churn_reason' => ['nullable', 'string', 'max:160'],
        ];
    }
}
