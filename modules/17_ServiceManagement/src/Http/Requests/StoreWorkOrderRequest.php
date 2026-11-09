<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkOrderRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'work_type' => ['nullable', 'string', 'in:corrective,preventive,inspection,installation,emergency,commissioning'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'service_category_id' => ['nullable', 'uuid', $this->tenantExists('esm_service_categories')],
            'customer_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'service_contract_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_contracts')],
            'asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'ticket_id' => ['nullable', 'uuid', $this->tenantOwns('esm_tickets')],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'service_team_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
            'lead_technician_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date'],
            'location_address' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.item_type' => ['nullable', 'string', 'in:labor,part,expense'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.is_billable' => ['nullable', 'boolean'],
            'billing_type' => ['nullable', 'string', 'in:contract_included,time_and_materials,fixed_fee,non_billable,internal_cost'],
        ];
    }
}
