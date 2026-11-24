<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of an assignment of an asset.
 */
final class AssignAssetRequest extends FormRequest
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
            'assignee_type' => ['required', 'string', 'in:employee,department,project,site,vehicle_operator'],
            'assignee_id' => ['nullable', 'uuid'],
            'assignee_name' => ['required', 'string', 'max:180'],
            'department_id' => ['nullable', 'uuid', $this->tenantOwns('hr_departments')],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'location_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_locations')],
            'assigned_at' => ['nullable', 'date'],
            'expected_return_at' => ['nullable', 'date', 'after_or_equal:assigned_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
