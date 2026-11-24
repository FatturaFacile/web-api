<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new quantity (seats) of the plan item, prorated from the effective date.
 */
final class ChangeQuantityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'effective_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
