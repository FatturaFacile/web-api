<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;

/**
 * A cancellation: reason code and text, immediate or at the end of the term, refund of the unused period.
 */
final class CancelSubscriptionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:255'],
            'reason_code' => ['nullable', 'string', 'in:'.implode(',', CancelSubscriptionDTO::REASON_CODES)],
            'cancellation_type' => ['nullable', 'string', 'in:'.implode(',', CancelSubscriptionDTO::TYPES)],
            'cancellation_date' => ['nullable', 'date', 'prohibited_if:cancellation_type,end_of_term'],
            'refund' => ['nullable', 'boolean'],
        ];
    }
}
