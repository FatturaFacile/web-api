<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Support\BillingModel;
use Modules\Projects\Support\BillingStatuses;

/**
 * Terms of the billing contract of a project (Mod 081); `expected_version` is required to update an existing contract.
 */
final class UpsertBillingContractRequest extends FormRequest
{
    use TenantScopedRules;

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
            'billing_model' => ['required', 'string', Rule::in(array_map(static fn (BillingModel $model): string => $model->value, BillingModel::cases()))],
            'fiscal_jurisdiction' => ['nullable', 'string', Rule::in(['IT', 'CH'])],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'contract_total_value' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'advance_payment_percent' => ['nullable', 'numeric', 'between:0,100'],
            'advance_payment_amount' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'retention_guarantee_percent' => ['nullable', 'numeric', 'between:0,100'],
            'retention_release_rule' => ['nullable', 'string', Rule::in(BillingStatuses::RetentionReleaseRules)],
            'retention_release_date' => ['nullable', 'date_format:Y-m-d'],
            'cap_amount' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'markup_percent' => ['nullable', 'numeric', 'between:0,1000'],
            'rate_card_id' => ['nullable', 'uuid', $this->tenantOwns('project_rate_cards')],
            'prepaid_hours_allowance' => ['nullable', 'numeric', 'gte:0', 'max:99999999'],
            'prepaid_amount_allowance' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'low_balance_alert_threshold_percent' => ['nullable', 'numeric', 'between:0,100'],
            'cig' => ['nullable', 'string', 'size:10', 'alpha_num'],
            'cup' => ['nullable', 'string', 'size:15', 'alpha_num'],
            'contract_reference' => ['nullable', 'string', 'max:100'],
            'contract_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
