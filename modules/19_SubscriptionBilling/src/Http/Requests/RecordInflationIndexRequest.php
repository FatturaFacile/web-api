<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SubscriptionBilling\Models\InflationIndex;

final class RecordInflationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'index_type' => ['required', Rule::in(InflationIndex::TYPES)],
            'reference_month' => ['required', 'date_format:Y-m'],
            'index_value' => ['required', 'numeric', 'gt:0'],
            'base_period' => ['nullable', 'string', 'max:30'],
            'source' => ['nullable', 'string', 'max:120'],
        ];
    }
}
