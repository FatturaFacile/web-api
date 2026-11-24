<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\Shifts\ShiftSequencePattern;

final class SaveShiftSequenceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'pattern' => ['required', Rule::enum(ShiftSequencePattern::class)],
            'steps' => ['present', 'array', 'max:366'],
            'steps.*' => ['nullable', 'uuid', TenantScopedRules::exists('hr_shift_types')],
            'skip_holidays' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
