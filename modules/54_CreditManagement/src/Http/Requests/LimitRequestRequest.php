<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

/**
 * Payload of a credit limit request. Without a requested limit the automatic proposal is asked for.
 */
final class LimitRequestRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', $this->ownRow('contacts')],
            'requested_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
