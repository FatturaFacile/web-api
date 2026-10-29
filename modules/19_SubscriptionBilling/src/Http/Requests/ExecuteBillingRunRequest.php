<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExecuteBillingRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'run_date' => ['nullable', 'date'],
            'lookahead_days' => ['nullable', 'integer', 'min:0', 'max:90'],
        ];
    }
}
