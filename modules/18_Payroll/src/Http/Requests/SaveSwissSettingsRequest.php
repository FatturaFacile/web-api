<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSwissSettingsRequest extends FormRequest
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
        $rate = ['sometimes', 'numeric', 'between:0,100'];

        return [
            'ahv_employee_rate_percent' => ['sometimes', 'numeric', 'between:0,20'],
            'ahv_employer_rate_percent' => $rate,
            'ahv_administration_cost_percent' => $rate,
            'alv_employee_rate_percent' => $rate,
            'alv_employer_rate_percent' => $rate,
            'alv_annual_ceiling' => ['sometimes', 'numeric', 'gt:0'],
            'uvg_annual_ceiling' => ['sometimes', 'numeric', 'gt:0'],
            'nbu_employee_rate_percent' => $rate,
            'bu_employer_rate_percent' => $rate,
            'ktg_employee_rate_percent' => $rate,
            'ktg_employer_rate_percent' => $rate,
            'fak_employer_rate_percent' => $rate,
            'bvg_employer_share_percent' => ['sometimes', 'numeric', 'between:50,100'],
            'bvg_risk_rate_percent' => $rate,
            'pensioner_allowance_monthly' => ['sometimes', 'numeric', 'min:0'],
            'default_work_canton' => ['sometimes', Rule::in(PayrollRules::Cantons)],
            'ahv_member_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'uvg_insurer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bvg_insurer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'withholding_debtor_numbers' => ['sometimes', 'nullable', 'array'],
            'withholding_debtor_numbers.*' => ['string', 'max:50'],
        ];
    }
}
