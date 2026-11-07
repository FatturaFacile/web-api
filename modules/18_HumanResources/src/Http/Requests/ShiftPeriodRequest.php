<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Requests\PayrollRules;

/**
 * A period of the plan: at most a year, so that one call cannot walk the calendar without end.
 */
final class ShiftPeriodRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.date('Y-m-d', strtotime($this->input('from').' +366 days') ?: time())],
            'department_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
            'employee_ids' => ['sometimes', 'array', 'max:1000'],
            'employee_ids.*' => ['uuid', TenantScopedRules::exists('hr_employees')],
            'canton' => ['nullable', Rule::in(PayrollRules::Cantons)],
            'acknowledgement_reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
