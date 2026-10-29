<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpgradePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_plan_id' => ['required', 'uuid'],
            'effective_date' => ['nullable', 'date'],
            'change_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
