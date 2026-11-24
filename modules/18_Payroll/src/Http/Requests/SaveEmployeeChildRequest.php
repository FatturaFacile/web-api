<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveEmployeeChildRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addMonths(9)->format('Y-m-d')],
            'in_education' => ['sometimes', 'boolean'],
            'education_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'allowance_eligible' => ['sometimes', 'boolean'],
        ];
    }
}
