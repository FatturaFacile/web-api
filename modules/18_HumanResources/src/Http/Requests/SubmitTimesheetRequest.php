<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'work_date' => ['required', 'date'],
            'regular_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'billable_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
