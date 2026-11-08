<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update of a CRM account. CRM attributes stay on the account; master data fields (name, VAT number, email...) are
 * written to the linked contact so that sales documents see the same values. `contact_id` relinks the account, which
 * is how an ambiguous backfill decision is resolved.
 */
final class UpdateAccountRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:200'],
            'contact_id' => ['sometimes', 'uuid', $this->tenantOwns('contacts')],
            'tier' => ['sometimes', 'nullable', 'string', 'in:strategic,enterprise,mid_market,smb,standard'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:100'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:prospect,active,inactive,churned'],
            'annual_revenue' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'employee_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'owner_user_id' => ['sometimes', 'nullable', 'uuid', $this->tenantMember()],
            ...StoreAccountRequest::masterDataRules(),
        ];
    }
}
