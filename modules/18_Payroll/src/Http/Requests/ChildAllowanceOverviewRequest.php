<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Http\Requests\TenantScopedRules;

final class ChildAllowanceOverviewRequest extends FormRequest
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
        return [...PayrollRules::period(), 'employee_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_employees')]];
    }
}
