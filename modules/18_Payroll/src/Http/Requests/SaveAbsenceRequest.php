<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Models\PayrollAbsence;

final class SaveAbsenceRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid'],
            'absence_type' => ['required', Rule::in(PayrollAbsence::Types)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'percent' => ['sometimes', 'numeric', 'between:1,100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
