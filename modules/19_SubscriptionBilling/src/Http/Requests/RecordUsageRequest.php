<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_item_id' => ['required', 'uuid'],
            'metric_code' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'event_timestamp' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
