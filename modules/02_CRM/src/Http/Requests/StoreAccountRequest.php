<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creation of a CRM account. The account references a contact (the party master): either an existing one through
 * `contact_id`, or one matched or created from the master data fields sent alongside the CRM attributes.
 */
final class StoreAccountRequest extends FormRequest
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
            'name' => ['required_without:contact_id', 'nullable', 'string', 'max:200'],
            'contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'tier' => ['nullable', 'string', 'in:strategic,enterprise,mid_market,smb,standard'],
            'industry' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],
            'owner_user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'parent_account_id' => ['nullable', 'uuid', $this->tenantOwns('crm_accounts')],
            ...self::masterDataRules(),
        ];
    }

    /**
     * Validation of the contact master data accepted by the account endpoints.
     *
     * @return array<string, list<string>>
     */
    public static function masterDataRules(): array
    {
        return [
            'vat_number' => ['nullable', 'string', 'max:30'],
            'fiscal_code' => ['nullable', 'string', 'max:16'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'pec' => ['nullable', 'email', 'max:255'],
            'sdi_code' => ['nullable', 'string', 'max:7'],
            'country' => ['nullable', 'string', 'size:2'],
        ];
    }
}
