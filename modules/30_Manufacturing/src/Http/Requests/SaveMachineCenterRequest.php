<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\WorkCenterStatus;

/**
 * Validation of a machine (PUT: every field optional, the code unique in the company).
 */
final class SaveMachineCenterRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'machine_code' => [$required, 'string', 'max:50', Rule::unique('mfg_machine_centers', 'machine_code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:200'],
            'work_center_id' => [$required, 'uuid', $this->tenantOwns('mfg_work_centers')],
            'asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'maintenance_schedule_id' => ['nullable', 'uuid', $this->tenantOwns('eam_maintenance_plans')],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'is_industria_40_enabled' => ['sometimes', 'boolean'],
            'is_transizione_50_enabled' => ['sometimes', 'boolean'],
            'telemetry_device_id' => ['nullable', 'string', 'max:120'],
            'telemetry_endpoint_key' => ['nullable', 'string', 'max:120'],
            'ideal_cycle_time_seconds_per_unit' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999'],
            'parallel_capacity_units' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'efficiency_factor_percent' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:200'],
            'hourly_machine_run_cost_rate_override' => ['nullable', ...self::Amount],
            'power_kw' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:999999'],
            'hourly_rate' => ['sometimes', ...self::Amount],
            'status' => ['sometimes', 'string', Rule::in(WorkCenterStatus::values())],
            // Profile read by the MES Core (names of the enums are the ones of Mes.Application / Mes.Domain.Oee).
            'mes_profile' => ['nullable', 'array:tag_roles,debounce_ms,stale_after_s,counter,oee_policy'],
            'mes_profile.tag_roles' => ['sometimes', 'array', 'max:100'],
            'mes_profile.tag_roles.*' => ['string', Rule::in(['Running', 'Alarm', 'Setup', 'Speed', 'TotalCounter', 'GoodCounter', 'Power', 'EnergyCumulative'])],
            'mes_profile.debounce_ms' => ['sometimes', 'integer', 'min:0', 'max:600000'],
            'mes_profile.stale_after_s' => ['sometimes', 'integer', 'min:1', 'max:86400'],
            'mes_profile.counter' => ['sometimes', 'array:rollover_modulus,max_delta_per_sample'],
            'mes_profile.counter.rollover_modulus' => ['nullable', 'integer', 'min:2'],
            'mes_profile.counter.max_delta_per_sample' => ['sometimes', 'integer', 'min:1'],
            'mes_profile.oee_policy' => ['sometimes', 'array:version,setup,gap'],
            'mes_profile.oee_policy.version' => ['sometimes', 'integer', 'min:1'],
            'mes_profile.oee_policy.setup' => ['sometimes', 'string', Rule::in(['AvailabilityLoss', 'PlannedExcluded'])],
            'mes_profile.oee_policy.gap' => ['sometimes', 'string', Rule::in(['ExcludeFromPlanned', 'CountAsDowntime'])],
        ];
    }
}
