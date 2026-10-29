<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRevenueRecognitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accounting_period_date' => ['required', 'date'],
            'override_completion_percentage' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
