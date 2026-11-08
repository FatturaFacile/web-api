<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Validation of an inspection plan with its characteristics.
 */
final class StoreQualityPlanRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:50', Rule::unique('mfg_quality_plans', 'plan_code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:200'],
            'inspection_type' => ['required', 'string', 'in:receipt,in_process,final'],
            'mfg_item_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_items')],
            'description' => ['nullable', 'string', 'max:5000'],
            'acceptance_defect_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
            'characteristics' => ['nullable', 'array', 'max:200'],
            'characteristics.*.characteristic_name' => ['required', 'string', 'max:100'],
            'characteristics.*.target_value' => ['nullable', 'numeric', 'decimal:0,4'],
            'characteristics.*.min_tolerance' => ['nullable', 'numeric', 'decimal:0,4'],
            'characteristics.*.max_tolerance' => ['nullable', 'numeric', 'decimal:0,4'],
            'characteristics.*.uom' => ['nullable', 'string', 'max:20'],
            'characteristics.*.inspection_method' => ['nullable', 'string', 'max:100'],
            'characteristics.*.is_critical' => ['nullable', 'boolean'],
        ];
    }
}
