<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\Shifts\ShiftCategory;

final class SaveShiftTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Working and on-call shifts have start and end; an absence has neither.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsTimes = in_array($this->input('category'), [ShiftCategory::Working->value, ShiftCategory::OnCall->value], true);

        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::enum(ShiftCategory::class)],
            'start_time' => [Rule::requiredIf($needsTimes), 'nullable', 'date_format:H:i'],
            'end_time' => [Rule::requiredIf($needsTimes), 'nullable', 'date_format:H:i'],
            'break_minutes' => ['sometimes', 'integer', 'between:0,480'],
            'is_paid_absence' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
