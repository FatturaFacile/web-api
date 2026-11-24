<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

/**
 * Payload to reject a credit limit request.
 */
final class RejectLimitRequestRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision_note' => ['required', 'string', 'max:2000'],
        ];
    }
}
