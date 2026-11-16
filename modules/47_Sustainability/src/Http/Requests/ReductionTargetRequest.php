<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Models\EsgReductionTarget;

/**
 * An absolute reduction target against a baseline year.
 */
final class ReductionTargetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'scope' => ['required', Rule::in(EsgReductionTarget::Scopes)],
            'baseline_year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'target_year' => ['required', 'integer', 'min:1991', 'max:2100'],
            'reduction_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        return $this->isMethod('PUT') ? array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules) : $rules;
    }
}
