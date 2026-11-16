<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

/**
 * Payload of a promise to pay on a case.
 */
final class PromiseToPayRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'case_id' => ['required', 'uuid', $this->ownRow('credit_collection_cases')],
            'promised_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'promised_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
