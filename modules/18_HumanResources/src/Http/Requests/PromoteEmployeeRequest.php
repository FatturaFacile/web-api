<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PromoteEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_position_id' => ['nullable', 'uuid'],
            'new_job_grade_id' => ['nullable', 'uuid'],
            'new_base_annual_salary' => ['required', 'numeric', 'min:0'],
            'effective_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
