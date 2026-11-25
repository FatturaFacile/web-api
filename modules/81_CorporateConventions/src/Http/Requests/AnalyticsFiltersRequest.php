<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AnalyticsFiltersRequest extends FormRequest
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
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'currency' => ['sometimes', 'nullable', Rule::in(['EUR', 'CHF'])],
            'country_code' => ['sometimes', 'nullable', Rule::in(['IT', 'CH'])],
            'convention_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
