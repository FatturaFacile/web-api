<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Http\Requests\TenantScopedRules;

final class ConstructionReportRequest extends FormRequest
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
            'agreement_code' => ['required', 'string', 'max:30'],
            'accounting_year' => ['required', 'integer', 'between:2000,2100'],
            'employee_id' => ['sometimes', 'uuid', TenantScopedRules::exists('hr_employees')],
        ];
    }
}
