<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A period of at most one year, optionally narrowed to a department, an employee or a user.
 */
final class HrPeriodRequest extends FormRequest
{
    /**
     * Access is checked by the route middleware and the controller.
     */
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.date('Y-m-d', strtotime((string) $this->input('from', 'today').' +366 days'))],
            'department_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
            'employee_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_employees')],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
