<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sets the billable utilization target of a department, a cost centre or (neither given) the whole tenant.
 */
final class UtilizationTargetRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'department_id' => ['nullable', 'uuid', 'prohibits:cost_center_id', Rule::exists('hr_departments', 'id')->where('tenant_id', $tenantId)],
            'cost_center_id' => ['nullable', 'uuid', Rule::exists('hr_cost_centers', 'id')->where('tenant_id', $tenantId)],
            'target_percent' => ['required', 'numeric', 'between:0,100'],
        ];
    }
}
