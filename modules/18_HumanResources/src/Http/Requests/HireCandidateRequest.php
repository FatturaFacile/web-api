<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class HireCandidateRequest extends FormRequest
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
            'base_annual_salary' => ['required', 'numeric', 'min:0'],
            'hire_date' => ['required', 'date_format:Y-m-d'],
            'work_email' => ['nullable', 'email', 'max:180'],
            'department_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
            'position_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_positions')],
            'employment_type' => ['nullable', 'string', 'max:40'],
            'contract_type' => ['nullable', 'string', 'max:40'],
            'weekly_hours' => ['nullable', 'numeric', 'between:1,60'],
            'probation_months' => ['nullable', 'integer', 'between:0,12'],
            'onboarding_template_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_onboarding_templates')],
        ];
    }
}
