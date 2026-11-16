<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditInsuranceCover;

/**
 * Payload of the cover granted on a customer under a policy.
 */
final class InsuranceCoverRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'customer_id' => $this->isMethod('PUT') ? ['prohibited'] : ['required', 'uuid', $this->ownRow('contacts')],
            'insured_limit' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'status' => ['nullable', Rule::in(CreditInsuranceCover::Statuses)],
            'granted_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
