<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportAccountingRequest extends FormRequest
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
        $amount = ['required', 'numeric', 'min:0', 'decimal:0,2'];

        return [
            'total_gross_wages' => $amount,
            'inps_employee_withholding' => $amount,
            'inps_employer_contribution' => $amount,
            'irpef_withholding' => $amount,
            'regional_municipal_addizionali' => $amount,
            'tfr_monthly_accrual' => $amount,
            'tfr_fondo_tesoreria_or_previdenza' => $amount,
            'inail_accrual' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'net_payable' => $amount,
            'company_id' => ['nullable', 'uuid'],
            'employee_nets' => ['nullable', 'array'],
            'employee_nets.*.employee_id' => ['required', 'uuid'],
            'employee_nets.*.net_amount' => $amount,
        ];
    }
}
