<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartOffboardingRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid', TenantScopedRules::exists('hr_employees')],
            'template_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_onboarding_templates')],
        ];
    }
}
