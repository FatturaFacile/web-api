<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Validation of a new work centre or of changes to one (PUT: every field optional, the code unique in the company).
 */
final class StoreWorkCenterRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $updating = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $required = $updating ? 'sometimes' : 'required';

        return [
            'work_center_code' => [$required, 'string', 'max:50', Rule::unique('mfg_work_centers', 'work_center_code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'department_code' => ['nullable', 'string', 'max:50'],
            'capacity_hours_per_day' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
            'efficiency_rate' => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:200'],
            'availability_rate' => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'hourly_cost_rate' => ['sometimes', ...self::Amount],
            'hourly_overhead_rate' => ['sometimes', ...self::Amount],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }
}
