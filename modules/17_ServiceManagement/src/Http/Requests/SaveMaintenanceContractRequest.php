<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\FieldService\CoveragePolicy;

/**
 * Create/update of a recurring maintenance contract. All uniqueness and existence rules are tenant-scoped.
 */
final class SaveMaintenanceContractRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenant()->id;
        $partial = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $contract = $this->route('serviceContract');
        $ignoreId = is_object($contract) ? $contract->getKey() : null;

        return [
            'customer_id' => [$partial, 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'contract_number' => [$partial, 'string', 'max:60', Rule::unique('esm_service_contracts', 'contract_number')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($ignoreId)],
            'title' => [$partial, 'string', 'max:180'],
            'start_date' => [$partial, 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'string', 'in:draft,active,suspended,expired,cancelled'],
            'billing_recurrence' => ['sometimes', 'string', 'in:monthly,quarterly,yearly'],
            'annual_value' => ['sometimes', 'numeric', 'min:0'],
            'sla_response_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'included_hours' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'low_balance_threshold_percent' => ['sometimes', 'integer', 'between:0,100'],
            'report_email' => ['nullable', 'email', 'max:255'],
            'asset_ids' => ['sometimes', 'array'],
            'asset_ids.*' => ['uuid', 'distinct', Rule::exists('esm_equipment_passports', 'id')->where('tenant_id', $tenantId)],
            'coverage_type' => ['nullable', 'in:'.implode(',', CoveragePolicy::types())],
            'preventive_frequency_rule' => ['nullable', 'array'],
            'preventive_frequency_rule.calendar' => ['nullable', 'array'],
            'preventive_frequency_rule.calendar.every' => ['required_with:preventive_frequency_rule.calendar', 'integer', 'min:1', 'max:120'],
            'preventive_frequency_rule.calendar.unit' => ['required_with:preventive_frequency_rule.calendar', 'in:day,week,month,year'],
            'preventive_frequency_rule.meter' => ['nullable', 'array'],
            'preventive_frequency_rule.meter.operating_hours' => ['nullable', 'numeric', 'gt:0'],
            'preventive_frequency_rule.meter.cycles' => ['nullable', 'integer', 'gt:0'],
            'preventive_frequency_rule.meter.lead_percent' => ['nullable', 'numeric', 'between:0,50'],
            'preventive_frequency_rule.lead_days' => ['nullable', 'integer', 'between:0,365'],
            'included_visits_per_year' => ['nullable', 'integer', 'min:0'],
            'callout_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'hourly_labor_rate' => ['nullable', 'numeric', 'min:0'],
            'recurring_billing_subscription_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'equipment_passport_ids' => ['nullable', 'array'],
            'equipment_passport_ids.*' => ['uuid', Rule::exists('esm_equipment_passports', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
