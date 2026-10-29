<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMaintenanceWorkOrderRequest extends FormRequest
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
        return [
            'asset_id' => ['required', 'uuid', 'exists:eam_fixed_assets,id'],
            'maintenance_type' => ['nullable', 'string', 'in:preventive,corrective,condition_based,emergency,upgrade'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'scheduled_date' => ['nullable', 'date'],
            'plan_id' => ['nullable', 'uuid', 'exists:eam_maintenance_plans,id'],
            'assigned_technician_id' => ['nullable', 'uuid', 'exists:users,id'],
            'problem_description' => ['nullable', 'string', 'max:2000'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
