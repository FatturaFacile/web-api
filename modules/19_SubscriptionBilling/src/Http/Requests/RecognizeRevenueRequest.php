<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecognizeRevenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_date' => ['nullable', 'date'],
        ];
    }
}
