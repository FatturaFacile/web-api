<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditDispute;

/**
 * Payload to decide a dispute as upheld or rejected.
 */
final class ResolveDisputeRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in([CreditDispute::Upheld, CreditDispute::Rejected])],
            'credited_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
