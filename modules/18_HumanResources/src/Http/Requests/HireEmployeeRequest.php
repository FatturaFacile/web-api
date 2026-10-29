<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class HireEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'work_email' => ['required', 'email', 'max:180'],
            'base_annual_salary' => ['required', 'numeric', 'min:0'],
            'hire_date' => ['nullable', 'date'],
            'department_id' => ['nullable', 'uuid'],
            'team_id' => ['nullable', 'uuid'],
            'business_unit_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
            'job_grade_id' => ['nullable', 'uuid'],
            'manager_id' => ['nullable', 'uuid'],
            'employment_type' => ['nullable', 'string', 'in:full_time,part_time,contractor,apprentice,seasonal'],
            'contract_type' => ['nullable', 'string', 'in:permanent,fixed_term,apprenticeship,contractor,internship'],
            'weekly_hours' => ['nullable', 'numeric', 'min:1', 'max:60'],
            'probation_months' => ['nullable', 'integer', 'min:0', 'max:12'],
        ];
    }
}
