<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StorePeriodRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'rate_type' => ['required', Rule::in(['closing', 'average', 'historical'])],
            'period_end' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
