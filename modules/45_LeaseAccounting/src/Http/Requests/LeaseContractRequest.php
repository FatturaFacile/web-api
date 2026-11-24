<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\LeaseAccounting\Models\LeaseContract;

/**
 * Terms of a lease contract, on creation (POST) or change of a draft (PUT).
 */
final class LeaseContractRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', 'max:190'],
            'lessor_name' => [$presence, 'string', 'max:190'],
            'lessor_contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'asset_class' => [$presence, Rule::in(LeaseContract::AssetClasses)],
            'asset_description' => ['nullable', 'string', 'max:2000'],
            'fixed_asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'accounting_standard' => ['sometimes', Rule::in(LeaseContract::Standards)],
            'currency' => [$presence, Rule::in(config('leases.currencies'))],
            'commencement_date' => [$presence, 'date_format:Y-m-d'],
            'term_months' => [$presence, 'integer', 'min:1', 'max:'.config('leases.max_term_months')],
            'payment_amount' => [$presence, 'numeric', 'decimal:0,2', 'gt:0'],
            'payment_frequency' => ['sometimes', Rule::in(array_keys(LeaseContract::FrequencyMonths))],
            'payment_timing' => ['sometimes', Rule::in(LeaseContract::Timings)],
            'escalation_type' => ['sometimes', Rule::in(LeaseContract::EscalationTypes)],
            'escalation_rate_percent' => ['nullable', 'numeric', 'decimal:0,6', 'between:0,100'],
            'incremental_borrowing_rate_percent' => [$presence, 'numeric', 'decimal:0,6', 'between:0,100'],
            'initial_direct_costs' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0'],
            'incentives_received' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0'],
            'purchase_option_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'purchase_option_reasonably_certain' => ['sometimes', 'boolean'],
            'extension_months' => ['nullable', 'integer', 'min:1', 'max:'.config('leases.max_term_months')],
            'extension_reasonably_certain' => ['sometimes', 'boolean'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'exemption' => ['sometimes', Rule::in(LeaseContract::Exemptions)],
            'underlying_asset_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'rou_asset_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'accumulated_depreciation_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'lease_liability_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'interest_expense_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'depreciation_expense_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
