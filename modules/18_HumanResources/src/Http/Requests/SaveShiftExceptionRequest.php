<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveShiftExceptionRequest extends FormRequest
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
            'exception_date' => ['required', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_employees')],
            'department_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
            'shift_type_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_shift_types')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
