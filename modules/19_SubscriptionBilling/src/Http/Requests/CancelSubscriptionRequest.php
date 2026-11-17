<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'cancellation_date' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'in:price,competitor,missing_features,support,no_longer_needed,payment_failure,other'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
