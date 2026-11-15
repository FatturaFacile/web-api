<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class HrAnalyticsRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'granularity' => ['nullable', Rule::in(['month', 'quarter', 'year'])],
            'days' => ['nullable', 'integer', 'between:1,365'],
        ];
    }
}
