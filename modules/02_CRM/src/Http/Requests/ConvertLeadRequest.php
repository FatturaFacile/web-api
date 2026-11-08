<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Conversion of a lead. The account and the contact can be chosen among the tenant's own records; otherwise they are
 * matched (contact by email or VAT number) or created.
 */
final class ConvertLeadRequest extends FormRequest
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
            'existing_account_id' => ['nullable', 'uuid', $this->tenantOwns('crm_accounts')],
            'existing_contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'new_account_name' => ['nullable', 'string', 'max:200'],
            'account_tier' => ['nullable', 'string', 'in:strategic,enterprise,mid_market,smb,standard'],
            'contact_role' => ['nullable', 'string', 'in:decision_maker,influencer,champion,evaluator,blocker,economic_buyer,technical_buyer'],
            'create_opportunity' => ['nullable', 'boolean'],
            'opportunity_name' => ['nullable', 'string', 'max:200'],
            'opportunity_amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_user_id' => ['nullable', 'uuid'],
        ];
    }
}
