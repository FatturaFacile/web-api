<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignShiftSequenceRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid', TenantScopedRules::exists('hr_employees')],
            'shift_sequence_id' => ['required', 'uuid', TenantScopedRules::exists('hr_shift_sequences')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'offset_days' => ['sometimes', 'integer', 'between:0,365'],
        ];
    }
}
