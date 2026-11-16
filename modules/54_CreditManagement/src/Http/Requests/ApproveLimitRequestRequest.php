<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

/**
 * Payload to approve a credit limit request: the limit defaults to the one requested.
 */
final class ApproveLimitRequestRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'approved_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'decision_note' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
