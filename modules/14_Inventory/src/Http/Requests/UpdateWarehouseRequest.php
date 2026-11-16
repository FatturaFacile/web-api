<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\PickingStrategy;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class UpdateWarehouseRequest extends FormRequest
{
    use ValidatesTenantRecords;

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
            'code' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:standard,van,transit,quarantine,consignment,virtual'],
            'picking_strategy' => ['nullable', Rule::enum(PickingStrategy::class)],
            'is_virtual' => ['nullable', 'boolean'],
            'owner_contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:2'],
            'is_active' => ['sometimes', 'boolean'],
            'manager_user_id' => ['nullable', 'uuid'],
            'warehouse_type' => ['sometimes', 'string', 'in:standard,transit,mobile_van,customer_consignment,vendor_consignment,subcontractor'],
            'legal_owner_type' => ['sometimes', 'string', 'in:company,vendor,customer'],
            'linked_partner_id' => ['nullable', 'uuid'],
            'company_entity_id' => ['nullable', 'uuid'],
        ];
    }
}
