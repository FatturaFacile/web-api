<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class InterestRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jurisdiction' => ['required', Rule::in(['CH', 'IT'])],
            'kind' => ['nullable', Rule::in(['statutory_ch', 'ecb_it'])],
            'base_rate_percent' => ['required', 'numeric', 'between:-5,100'],
            'markup_percent' => ['nullable', 'numeric', 'between:0,100'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
