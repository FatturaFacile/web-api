<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceManagement\FieldService\CoveragePolicy;

/**
 * Creates or updates a maintenance contract: coverage, preventive frequency rule (calendar and/or meter thresholds),
 * visit allowance and the callout fee and hourly rate used when billing service reports.
 */
final class SaveMaintenanceContractRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $required = $create ? 'required' : 'sometimes';

        return [
            'customer_id' => [$required, 'uuid'],
            'contract_number' => [$required, 'string', 'max:60'],
            'title' => [$required, 'string', 'max:180'],
            'start_date' => [$required, 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'in:draft,active,suspended,expired,cancelled'],
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
            'equipment_passport_ids.*' => ['uuid', $this->tenantOwns('esm_equipment_passports')],
        ];
    }
}
