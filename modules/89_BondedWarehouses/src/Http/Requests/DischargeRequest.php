<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BondedWarehouses\Domain\Enums\AllocationMethod;
use Modules\BondedWarehouses\Domain\Enums\DischargeType;
use Modules\BondedWarehouses\Domain\Enums\UsualHandlingOperation;
use Modules\BondedWarehouses\Domain\Support\CustomsCodes;

/**
 * Discharge of bonded stock and its simulation (same payload).
 */
final class DischargeRequest extends FormRequest
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
        $fifo = 'required_if:allocation_method,'.AllocationMethod::FIFO->value;
        $specific = 'required_if:allocation_method,'.AllocationMethod::SpecificMRN->value;
        $simulating = $this->routeIs('bonded-warehouses.mrn-lots.discharge.simulate');

        return [
            'authorization_id' => ['required', 'uuid', Rule::exists('customs_warehouse_authorizations', 'id')->where('tenant_id', $tenantId)],
            'discharge_type' => ['required', Rule::enum(DischargeType::class)],
            'allocation_method' => ['required', Rule::enum(AllocationMethod::class)],
            'discharge_date' => ['required', 'date'],
            'product_id' => [$fifo, 'nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'quantity' => [$fifo, 'nullable', 'numeric', 'gt:0'],
            'allocations' => [$specific, 'nullable', 'array', 'min:1'],
            'allocations.*.mrn_lot_id' => ['required_with:allocations', 'uuid', Rule::exists('customs_bonded_mrn_lots', 'id')->where('tenant_id', $tenantId)],
            'allocations.*.quantity' => ['required_with:allocations', 'numeric', 'gt:0'],
            'allocations.*.net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'discharge_mrn_number' => [$simulating ? 'nullable' : 'required_unless:discharge_type,'.DischargeType::UsualHandlingAnnex7103->value, 'nullable', 'string', 'size:18', 'regex:/^\d{2}[A-Z]{2}[A-Z0-9]{14}$/'],
            'usual_handling_code' => ['required_if:discharge_type,'.DischargeType::UsualHandlingAnnex7103->value, 'nullable', Rule::in(UsualHandlingOperation::values())],
            'usual_handling_notes' => ['nullable', 'string', 'max:2000'],
            'customs_prospectus_document_id' => ['nullable', 'uuid', Rule::exists('customs_goods_declarations', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('discharge_mrn_number'))) {
            $this->merge(['discharge_mrn_number' => CustomsCodes::normalizeMrn($this->input('discharge_mrn_number'))]);
        }
    }
}
