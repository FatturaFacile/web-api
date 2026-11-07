<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Http\Requests\TenantScopedRules;

final class CreatePayrollPaymentsRequest extends FormRequest
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
            'allocations' => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.bank_account_id' => ['required', 'uuid', TenantScopedRules::exists('bank_accounts')],
            'allocations.*.employee_ids' => ['nullable', 'array'],
            'allocations.*.employee_ids.*' => ['uuid', TenantScopedRules::exists('hr_employees')],
            'allocations.*.department_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
        ];
    }
}
