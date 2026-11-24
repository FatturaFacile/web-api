<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\Enums\LeaveUnitMode;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;

final class SaveLeaveEntitlementPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageConfiguration', LeaveEntitlementPolicy::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'country' => ['required', Rule::enum(LeaveCountry::class)],
            'leave_type' => ['required', Rule::enum(LeaveTypeCode::class)],
            'ccnl_or_contract_ref' => ['nullable', 'string', 'max:120'],
            'unit_mode' => ['required', Rule::enum(LeaveUnitMode::class)],
            'annual_entitlement' => ['required', 'numeric', 'min:0', 'max:3000'],
            'annual_entitlement_under_20' => ['nullable', 'numeric', 'min:0', 'max:3000'],
            'annual_entitlement_over_50' => ['nullable', 'numeric', 'min:0', 'max:3000'],
            'hours_per_workday' => ['nullable', 'numeric', 'min:1', 'max:24'],
            'accrual_frequency' => ['required', Rule::in(['monthly', 'yearly', 'none'])],
            'prorate_by_fte' => ['nullable', 'boolean'],
            'prorate_hire_termination' => ['nullable', 'boolean'],
            'min_days_worked_in_month_for_accrual' => ['nullable', 'integer', 'min:0', 'max:31'],
            'max_carry_over' => ['nullable', 'numeric', 'min:0', 'max:3000'],
            'expiration_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'inps_18_month_alert_enabled' => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'requires_medical_certificate' => ['nullable', 'boolean'],
            'requires_inps_puc' => ['nullable', 'boolean'],
            'waiting_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'employer_indemnity_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'insurer_indemnity_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'insurer_indemnity_percent_tier2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'comporto_max_days' => ['nullable', 'integer', 'min:1', 'max:1095'],
            'comporto_window_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ch_curtailment_grace_months' => ['nullable', 'integer', 'min:0', 'max:12'],
            'ch_curtailment_enabled' => ['nullable', 'boolean'],
            'legge_104_monthly_days_limit' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'is_paid' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
