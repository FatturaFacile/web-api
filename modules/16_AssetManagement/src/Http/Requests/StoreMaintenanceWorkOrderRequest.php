<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a new maintenance work order.
 */
final class StoreMaintenanceWorkOrderRequest extends FormRequest
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
            'asset_id' => ['required', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'plan_id' => ['nullable', 'uuid', $this->tenantOwns('eam_maintenance_plans')],
            'maintenance_type' => ['nullable', 'string', 'in:preventive,corrective,condition_based,emergency,upgrade'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'scheduled_date' => ['nullable', 'date'],
            'assigned_technician_id' => ['nullable', 'uuid', $this->tenantMember()],
            'problem_description' => ['nullable', 'string', 'max:5000'],
            'estimated_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
