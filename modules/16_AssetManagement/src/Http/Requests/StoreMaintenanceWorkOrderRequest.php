<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreMaintenanceWorkOrderRequest extends FormRequest
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
        return [
            'asset_id' => ['required', 'uuid', $this->tenantExists('eam_fixed_assets')],
            'maintenance_type' => ['nullable', 'string', 'in:preventive,corrective,condition_based,emergency,upgrade'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'scheduled_date' => ['nullable', 'date'],
            'plan_id' => ['nullable', 'uuid', $this->tenantExists('eam_maintenance_plans')],
            'assigned_technician_id' => ['nullable', 'uuid', $this->tenantMember()],
            'problem_description' => ['nullable', 'string', 'max:2000'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
