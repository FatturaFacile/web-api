<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Http\Requests\TenantScopedRules;

final class CalculateShortTimeClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Without hours, an employee's hours come from the working time records.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...PayrollRules::period(),
            'employees' => ['required', 'array', 'min:1', 'max:1000'],
            'employees.*.employee_id' => ['required', 'uuid', TenantScopedRules::exists('hr_employees')],
            'employees.*.target_hours' => ['nullable', 'numeric', 'between:0,400'],
            'employees.*.worked_hours' => ['nullable', 'numeric', 'between:0,400'],
            'employees.*.absence_hours' => ['nullable', 'numeric', 'between:0,400'],
            'employees.*.overtime_hours' => ['nullable', 'numeric', 'between:0,400'],
            'employees.*.regular_allowances' => ['nullable', 'numeric', 'min:0'],
            'employees.*.consented' => ['sometimes', 'boolean'],
        ];
    }
}
