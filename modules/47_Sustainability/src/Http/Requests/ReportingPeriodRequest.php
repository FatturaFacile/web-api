<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reporting period with the revenue and the headcount the intensity ratios are computed on.
 */
final class ReportingPeriodRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'revenue' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'currency' => ['nullable', Rule::in(['EUR', 'CHF'])],
            'employees_fte' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];

        return $this->isMethod('PUT') ? array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules) : $rules;
    }
}
