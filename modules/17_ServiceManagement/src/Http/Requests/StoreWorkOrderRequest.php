<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkOrderRequest extends FormRequest
{
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
            'service_category_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'service_contract_id' => ['nullable', 'uuid'],
            'asset_id' => ['nullable', 'uuid'],
            'ticket_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'service_team_id' => ['nullable', 'uuid'],
            'lead_technician_id' => ['nullable', 'uuid'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date'],
            'location_address' => ['nullable', 'string', 'max:255'],
            'billing_type' => ['nullable', 'string', 'in:contract_included,time_and_materials,fixed_fee,non_billable,internal_cost'],
        ];
    }
}
