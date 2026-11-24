<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

/**
 * A partner registers a deal for a customer.
 */
final class SubmitDealRegistrationRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'partner_id' => ['required', 'uuid', $this->ownRow('crm_partners')],
            'opportunity_id' => ['nullable', 'uuid', $this->ownRow('crm_opportunities')],
            'customer_name' => ['required', 'string', 'max:200'],
            'expected_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'registered_on' => ['sometimes', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:registered_on'],
        ];
    }
}
