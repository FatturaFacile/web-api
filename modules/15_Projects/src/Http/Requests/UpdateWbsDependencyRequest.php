<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Wbs\Enums\DependencyType;

/**
 * Changes the type and the lag of a WBS dependency; its two ends are fixed.
 */
final class UpdateWbsDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('dependency_type'))) {
            $this->merge(['dependency_type' => strtoupper($this->input('dependency_type'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dependency_type' => ['sometimes', 'string', Rule::enum(DependencyType::class)],
            'lag_days' => ['sometimes', 'integer', 'between:-3650,3650'],
        ];
    }
}
