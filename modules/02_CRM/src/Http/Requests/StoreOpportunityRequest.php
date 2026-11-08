<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * References to other records are validated against the active tenant (TenantScopedRules).
 */
final class StoreOpportunityRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid', $this->tenantOwns('crm_accounts')],
            'contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'name' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'stage_code' => ['nullable', 'string', 'max:50'],
            'forecast_category' => ['nullable', 'string', 'in:pipeline,best_case,commit,closed,omitted'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'campaign_id' => ['nullable', 'uuid', $this->tenantOwns('crm_campaigns')],
            'lead_id' => ['nullable', 'uuid', $this->tenantOwns('crm_leads')],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'line_items' => ['nullable', 'array'],
            'line_items.*.description' => ['required_with:line_items', 'string'],
            'line_items.*.quantity' => ['required_with:line_items', 'numeric', 'min:0.01'],
            'line_items.*.unit_price' => ['required_with:line_items', 'numeric', 'min:0'],
            'tags' => ['nullable', 'array'],
        ];
    }
}
