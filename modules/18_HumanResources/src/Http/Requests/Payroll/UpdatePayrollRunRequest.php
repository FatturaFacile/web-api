<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Models\Payroll\PayrollRun;

final class UpdatePayrollRunRequest extends FormRequest
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
        return StorePayrollRunRequest::sharedRules();
    }

    /**
     * @return list<array{employee_id: string, wage_type_code: string, amount: string, label: string|null}>|null
     */
    public function payrollInputs(): ?array
    {
        if (! $this->has('inputs')) {
            return null;
        }

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
