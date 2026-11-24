<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SimulateHiringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Exactly one of the annual gross salary and the wanted monthly net.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'annual_gross' => ['required_without:target_net_monthly', 'prohibits:target_net_monthly', 'nullable', 'numeric', 'gt:0', 'max:100000000'],
            'target_net_monthly' => ['required_without:annual_gross', 'nullable', 'numeric', 'gt:0', 'max:10000000'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'fte_ratio' => ['nullable', 'numeric', 'gt:0', 'max:1'],
            'salary_installments_per_year' => ['nullable', 'integer', 'between:12,14'],
            'withholding_tax_rate_percent' => ['nullable', 'numeric', 'between:0,100'],
            'pension_fund_employee_rate_percent' => ['nullable', 'numeric', 'between:0,100'],
            'residence_country' => ['nullable', 'string', 'size:2'],
        ];
    }
}
