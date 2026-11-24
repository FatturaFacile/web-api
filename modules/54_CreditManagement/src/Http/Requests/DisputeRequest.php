<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditDispute;

/**
 * Payload to raise a dispute on an invoice of a customer.
 */
final class DisputeRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', $this->ownRow('contacts')],
            'invoice_id' => ['nullable', 'uuid'],
            'case_id' => ['nullable', 'uuid', $this->ownRow('credit_collection_cases')],
            'disputed_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'reason_code' => ['required', Rule::in(CreditDispute::ReasonCodes)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
