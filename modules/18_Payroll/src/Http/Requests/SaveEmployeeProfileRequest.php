<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Services\Frontier\FrontierRegime;

final class SaveEmployeeProfileRequest extends FormRequest
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
        $isSwiss = strtoupper((string) app(TenantContext::class)->getTenant()?->country) === 'CH';

        return [
            'social_security_number' => ['sometimes', 'nullable', 'string', 'max:30', Rule::when($isSwiss, 'regex:/^756\.?\d{4}\.?\d{4}\.?\d{2}$/')],
            'iban' => ['sometimes', 'nullable', 'string', 'max:34'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'house_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', Rule::in(['M', 'F'])],
            'residence_permit' => ['sometimes', 'nullable', Rule::in(PayrollRules::Permits)],
            'residence_country' => ['sometimes', 'string', 'size:2'],
            'residence_canton' => ['sometimes', 'nullable', Rule::in(PayrollRules::Cantons)],
            'work_canton' => ['sometimes', 'nullable', Rule::in(PayrollRules::Cantons)],
            'civil_status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'church_tax' => ['sometimes', 'boolean'],
            'pay_type' => ['sometimes', Rule::in(['monthly', 'hourly'])],
            'salary_installments_per_year' => ['sometimes', 'integer', 'between:12,14'],
            'hourly_wage' => ['sometimes', 'numeric', 'min:0'],
            'weekly_hours' => ['sometimes', 'numeric', 'between:0,80'],
            'holiday_weeks' => ['sometimes', 'integer', 'between:0,12'],
            'is_fixed_term_contract' => ['sometimes', 'boolean'],
            'is_withholding_tax_subject' => ['sometimes', 'boolean'],
            'withholding_tax_code' => [Rule::requiredIf($this->boolean('is_withholding_tax_subject')), 'nullable', 'string', 'regex:/^[A-Z][0-9][NY]$/'],
            'withholding_tax_rate_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'italian_tax_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{16}$/'],
            'frontier_regime' => ['sometimes', 'nullable', Rule::enum(FrontierRegime::class)],
            'frontier_status_valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'frontier_commune_in_zone' => ['sometimes', 'nullable', 'boolean'],
            'frontier_returns_daily' => ['sometimes', 'boolean'],
            'frontier_apply_80_percent_basis' => ['sometimes', 'boolean'],
            'waive_retiree_allowance' => ['sometimes', 'boolean'],
            'extrapolate_qst_to_full_time' => ['sometimes', 'boolean'],
            'other_employment_fte_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'qst_rate_determining_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'swiss_permit_type' => ['sometimes', 'nullable', Rule::in(PayrollRules::Permits)],
            'swiss_permit_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'swiss_permit_valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'spouse_work_status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'religion_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'single_parent_concubinage' => ['sometimes', 'boolean'],
            'ccl_zone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'non_occupational_accident_rate_percent' => $rate,
            'occupational_accident_rate_percent' => $rate,
            'daily_sickness_rate_percent' => $rate,
            'family_allowance_fund_rate_percent' => $rate,
            'pension_fund_employee_rate_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'regional_surcharge_rate_percent' => $rate,
            'municipal_surcharge_rate_percent' => $rate,
            'collective_agreement_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'contract_level' => ['sometimes', 'nullable', 'string', 'max:30'],
            'inps_qualification' => ['sometimes', 'nullable', 'string', 'max:30'],
            'region_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'municipality_code' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }
}
