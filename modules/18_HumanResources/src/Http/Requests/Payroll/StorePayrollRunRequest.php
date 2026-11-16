<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Models\Payroll\PayrollRun;

final class StorePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PayrollRun::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ], self::sharedRules());
    }

    /**
     * Rules shared with the update request (payment date, employee selection and variable inputs).
     *
     * @return array<string, mixed>
     */
    public static function sharedRules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $employeeExists = Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return [
            'payment_date' => ['nullable', 'date_format:Y-m-d'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['uuid', 'distinct', $employeeExists],
            'inputs' => ['nullable', 'array'],
            'inputs.*.employee_id' => ['required', 'uuid', $employeeExists],
            'inputs.*.wage_type_code' => ['required', 'string', 'max:20'],
            'inputs.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'inputs.*.label' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<array{employee_id: string, wage_type_code: string, amount: string, label: string|null}>
     */
    public function payrollInputs(): array
    {
        $inputs = [];

        foreach ((array) $this->input('inputs', []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $inputs[] = [
                'employee_id' => (string) ($line['employee_id'] ?? ''),
                'wage_type_code' => (string) ($line['wage_type_code'] ?? ''),
                'amount' => (string) ($line['amount'] ?? '0'),
                'label' => isset($line['label']) ? (string) $line['label'] : null,
            ];
        }

        return $inputs;
    }
}
