<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a field work order from the web dispatcher or the mobile app. `client_uuid` makes the creation idempotent per
 * tenant: sending it twice returns the work order created the first time.
 */
final class StoreFieldWorkOrderRequest extends FormRequest
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
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'work_order_type' => ['required', 'in:preventive,corrective,installation,warranty'],
            'customer_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'problem_description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'equipment_passport_id' => ['nullable', 'uuid', $this->tenantOwns('esm_equipment_passports')],
            'service_contract_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_contracts')],
            'source_ticket_id' => ['nullable', 'uuid', $this->tenantOwns('esm_tickets')],
            'site_address' => ['nullable', 'string', 'max:255'],
            'site_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'site_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'required_skills' => ['nullable', 'array', 'max:30'],
            'required_skills.*' => ['string', 'max:100'],
            'required_parts' => ['nullable', 'array', 'max:50'],
            'required_parts.*.product_id' => ['required', 'uuid'],
            'required_parts.*.quantity' => ['required', 'numeric', 'gt:0'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
