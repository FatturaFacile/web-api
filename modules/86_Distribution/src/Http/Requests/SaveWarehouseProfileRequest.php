<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Domain\Enums\BillingFrequency;
use Modules\Distribution\Domain\Enums\SettlementTrigger;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Network profile of a warehouse: role, hub, third party and consignment terms.
 */
final class SaveWarehouseProfileRequest extends FormRequest
{
    use ScopesToTenant;

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
            'role' => ['required', 'string', Rule::in(WarehouseRole::values())],
            'parent_warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'default_transit_warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'third_party_partner_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'settlement_trigger' => ['nullable', 'string', Rule::in(SettlementTrigger::values())],
            'billing_frequency' => ['nullable', 'string', Rule::in(BillingFrequency::values())],
            'max_consignment_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_insured' => ['sometimes', 'boolean'],
            'counts_in_valuation' => ['sometimes', 'boolean'],
            'include_in_free_stock' => ['sometimes', 'boolean'],
            'is_blocked' => ['sometimes', 'boolean'],
            'is_counting' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
