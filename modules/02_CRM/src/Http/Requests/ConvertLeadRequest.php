<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'existing_account_id' => ['nullable', 'uuid'],
            'existing_contact_id' => ['nullable', 'uuid'],
            'new_account_name' => ['nullable', 'string', 'max:200'],
            'account_tier' => ['nullable', 'string', 'in:strategic,enterprise,mid_market,smb,standard'],
            'contact_role' => ['nullable', 'string', 'in:decision_maker,influencer,champion,evaluator,blocker,economic_buyer,technical_buyer'],
            'create_opportunity' => ['nullable', 'boolean'],
            'opportunity_name' => ['nullable', 'string', 'max:200'],
            'opportunity_amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            // The owner must be a member of the active tenant: never assign records to a foreign user.
            'owner_user_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId)],
        ];
    }
}
