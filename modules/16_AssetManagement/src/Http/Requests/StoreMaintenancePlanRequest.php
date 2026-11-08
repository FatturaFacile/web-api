<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of a preventive maintenance plan (time, meter or both).
 */
final class StoreMaintenancePlanRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policies of the controller.
     */
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
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:200'],
            'plan_code' => ['nullable', 'string', 'max:60', Rule::unique('eam_maintenance_plans', 'plan_code')->where('tenant_id', $this->tenantId())->ignore($this->route('id'))],
            'asset_id' => ['nullable', $this->isMethod('post') ? 'required_without:asset_class_id' : 'sometimes', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'asset_class_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_classes')],
            'maintenance_type' => ['nullable', 'string', 'in:preventive,condition_based,predictive,calibration'],
            'trigger_type' => ['nullable', 'string', 'in:time,meter,time_or_meter'],
            'frequency_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'meter_code' => ['nullable', 'required_if:trigger_type,meter,time_or_meter', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'meter_interval_units' => ['nullable', 'required_if:trigger_type,meter,time_or_meter', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'estimated_duration_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'estimated_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'next_due_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'checklist' => ['nullable', 'array', 'max:200'],
            'checklist.*' => ['string', 'max:500'],
        ];
    }

    /**
     * The active tenant.
     */
    private function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }
}
