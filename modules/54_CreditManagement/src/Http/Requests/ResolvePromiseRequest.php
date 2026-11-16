<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditPromiseToPay;

/**
 * Payload to close a pending promise to pay.
 */
final class ResolvePromiseRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in([CreditPromiseToPay::Kept, CreditPromiseToPay::Broken, CreditPromiseToPay::Cancelled])],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'resolved_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
