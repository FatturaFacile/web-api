<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollEmployeeProfile;

/**
 * The payroll profile of an employee, with the decrypted sensitive fields.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin PayrollEmployeeProfile
 */
final class PayrollEmployeeProfileResource extends JsonResource
{
    use FormatsDateAttributes;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'social_security_number' => $this->social_security_number,
            'iban' => $this->iban,
            'street' => $this->street,
            'house_number' => $this->house_number,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'gender' => $this->gender,
            'residence_permit' => $this->residence_permit,
            'residence_country' => $this->residence_country,
            'residence_canton' => $this->residence_canton,
            'work_canton' => $this->work_canton,
            'civil_status' => $this->civil_status,
            'church_tax' => $this->church_tax,
            'pay_type' => $this->pay_type,
            'salary_installments_per_year' => $this->salary_installments_per_year,
            'hourly_wage' => $this->hourly_wage,
            'weekly_hours' => $this->weekly_hours,
            'holiday_weeks' => $this->holiday_weeks,
            'is_fixed_term_contract' => $this->is_fixed_term_contract,
            'is_withholding_tax_subject' => $this->is_withholding_tax_subject,
            'withholding_tax_code' => $this->withholding_tax_code,
            'withholding_tax_rate_percent' => $this->withholding_tax_rate_percent,
            'non_occupational_accident_rate_percent' => $this->non_occupational_accident_rate_percent,
            'occupational_accident_rate_percent' => $this->occupational_accident_rate_percent,
            'daily_sickness_rate_percent' => $this->daily_sickness_rate_percent,
            'family_allowance_fund_rate_percent' => $this->family_allowance_fund_rate_percent,
            'pension_fund_employee_rate_percent' => $this->pension_fund_employee_rate_percent,
            'regional_surcharge_rate_percent' => $this->regional_surcharge_rate_percent,
            'municipal_surcharge_rate_percent' => $this->municipal_surcharge_rate_percent,
            'collective_agreement_code' => $this->collective_agreement_code,
            'contract_level' => $this->contract_level,
            'inps_qualification' => $this->inps_qualification,
            'region_code' => $this->region_code,
            'municipality_code' => $this->municipality_code,
            'birth_place' => $this->birth_place,
            'italian_tax_code' => $this->italian_tax_code,
            'frontier_regime' => $this->frontier_regime,
            'frontier_status_valid_from' => $this->dateOnly('frontier_status_valid_from'),
            'frontier_commune_in_zone' => $this->frontier_commune_in_zone,
            'frontier_returns_daily' => $this->frontier_returns_daily,
            'ccl_zone' => $this->ccl_zone,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
        ];
    }
}
