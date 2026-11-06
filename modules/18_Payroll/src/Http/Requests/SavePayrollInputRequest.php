<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Payroll\Calculation\Dec;

final class SavePayrollInputRequest extends FormRequest
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
            ...PayrollRules::period(),
            'employee_id' => ['required', 'uuid'],
            'wage_type_code' => ['required', 'string', 'max:6'],
            'quantity' => ['sometimes', 'numeric'],
            'rate' => ['sometimes', 'numeric'],
            'amount' => ['sometimes', 'numeric'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (Dec::isZero((string) $this->input('amount', '0')) && Dec::isZero((string) $this->input('quantity', '0'))) {
                $validator->errors()->add('amount', 'Indicare quantità o importo.');
            }
        }];
    }
}
