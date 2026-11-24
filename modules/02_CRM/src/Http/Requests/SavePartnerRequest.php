<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CRM\Models\Partner;

/**
 * A channel partner. The tier is not given here: it follows the closed revenue (tier evaluation).
 */
final class SavePartnerRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:200'],
            'account_id' => ['nullable', 'uuid', $this->ownRow('crm_accounts')],
            'partner_type' => ['required', Rule::in(Partner::Types)],
            'status' => ['required', Rule::in(Partner::Statuses)],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'joined_on' => ['nullable', 'date_format:Y-m-d'],
            'manager_user_id' => ['nullable', 'uuid'],
        ]);
    }
}
